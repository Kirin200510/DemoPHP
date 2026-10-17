import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import cv2
import numpy as np

from mrz_image import is_filler_glyph, row_filler_evidence
from mrz_parser import parse_mrz, repair_visual_fillers
from structured_extraction import build_structured_result


FIRST = "IDVNM1234567890123456789012<<1"
SECOND = "9001011M3001019VNM" + "<" * 11 + "0"
THIRD = "MACK<<VAN<KAC".ljust(30, "<")


def draw_rows(texts):
    image = np.full((len(texts) * 70, 1320, 3), 255, dtype=np.uint8)
    lines = []
    for index, text in enumerate(texts):
        for position, character in enumerate(text):
            cv2.putText(image, character, (position * 44 + 5, index * 70 + 50), cv2.FONT_HERSHEY_SIMPLEX, 1.2, (0, 0, 0), 2)
        lines.append({"index": index, "text": text, "confidence": 0.9, "polygon": [[0, index * 70], [1319, index * 70], [1319, index * 70 + 69], [0, index * 70 + 69]]})
    return image, lines


class MrzImageTest(unittest.TestCase):
    def test_filler_shape_does_not_match_letters_c_k_or_zero(self):
        for character in ("<", "C", "K", "0", "E", "X"):
            with self.subTest(character=character):
                glyph = np.zeros((70, 70), dtype=np.uint8)
                cv2.putText(glyph, character, (8, 54), cv2.FONT_HERSHEY_SIMPLEX, 1.5, 255, 2)

                self.assertEqual(is_filler_glyph(glyph), character == "<")

    def test_monospaced_row_provides_thirty_glyph_observations(self):
        image, lines = draw_rows([THIRD])

        evidence = row_filler_evidence(image, lines)

        self.assertEqual(evidence, [character == "<" for character in THIRD])

    def test_incomplete_or_unreadable_image_does_not_provide_evidence(self):
        image, lines = draw_rows([THIRD[:20]])

        self.assertIsNone(row_filler_evidence(image, lines))
        self.assertIsNone(row_filler_evidence(np.full_like(image, 255), lines))
        self.assertIsNone(row_filler_evidence(image, [{"text": THIRD}]))

    def test_split_ocr_polygons_are_read_as_one_visual_mrz_row(self):
        image, lines = draw_rows([THIRD])
        first, last = dict(lines[0]), dict(lines[0])
        first["polygon"] = [[0, 0], [659, 0], [659, 69], [0, 69]]
        last["polygon"] = [[660, 0], [1319, 0], [1319, 69], [660, 69]]

        evidence = row_filler_evidence(image, [last, first])

        self.assertEqual(evidence, [character == "<" for character in THIRD])

    def test_alignment_repairs_delimiters_and_overlong_padding_but_preserves_true_c_k(self):
        expected = "MACK<<VAN<AN".ljust(30, "<")
        evidence = [character == "<" for character in expected]
        noisy = expected.replace("<", "C") + "KKCC"

        repaired = repair_visual_fillers(noisy, 2, evidence)

        self.assertEqual(repaired, expected)

    def test_middle_row_check_digits_are_not_replaced_even_with_false_visual_detection(self):
        evidence = [True] * 30

        repaired = repair_visual_fillers(SECOND.replace("<", "K"), 1, evidence)

        self.assertEqual(repaired, SECOND)

    def test_first_row_identity_digits_are_protected_even_with_false_visual_detection(self):
        evidence = [True] * 30

        repaired = repair_visual_fillers(FIRST.replace("<", "C"), 0, evidence)

        self.assertEqual(repaired, FIRST)

    def test_single_misread_separator_is_corrected_without_rewriting_real_name_letters(self):
        for replacement in ("C", "K", "0"):
            with self.subTest(replacement=replacement):
                noisy = THIRD[:4] + replacement + THIRD[5:]

                repaired = repair_visual_fillers(noisy, 2, [character == "<" for character in THIRD])

                self.assertEqual(repaired, THIRD)

    def test_noisy_padding_alignment_recovers_deletions_at_the_end_without_inventing_name_letters(self):
        expected = "MACK<<VAN<AN".ljust(30, "<")
        noisy = expected.replace("<", "K")[:-3]

        repaired = repair_visual_fillers(noisy, 2, [character == "<" for character in expected])

        self.assertEqual(repaired, expected)

    def test_two_equally_plausible_name_alignments_require_review_instead_of_guessing(self):
        noisy = THIRD.replace("<", "C") + "KKCC"

        repaired = repair_visual_fillers(noisy, 2, [character == "<" for character in THIRD])

        self.assertIsNone(repaired)

    def test_image_verified_internal_separator_may_correct_other_single_letter_confusions(self):
        noisy = THIRD[:4] + "E" + THIRD[5:]

        repaired = repair_visual_fillers(noisy, 2, [character == "<" for character in THIRD])

        self.assertEqual(repaired, THIRD)

    def test_extra_separator_glyph_is_aligned_without_changing_the_name(self):
        noisy = THIRD[:4] + "CCC" + THIRD[6:]

        repaired = repair_visual_fillers(noisy, 2, [character == "<" for character in THIRD])

        self.assertEqual(repaired, THIRD)

    def test_glyph_detection_handles_scaled_rows_and_keeps_true_c_k_zero(self):
        image, lines = draw_rows([THIRD])
        for scale in (0.75, 1.5):
            with self.subTest(scale=scale):
                resized = cv2.resize(image, None, fx=scale, fy=scale)
                parts = [{**lines[0], "polygon": [[x * scale, y * scale] for x, y in lines[0]["polygon"]]}]

                evidence = row_filler_evidence(resized, parts)

                self.assertEqual(evidence, [character == "<" for character in THIRD])

    def test_non_padding_content_is_not_silently_deleted_to_force_thirty_cells(self):
        evidence = [character == "<" for character in THIRD]

        self.assertIsNone(repair_visual_fillers(THIRD + "AN", 2, evidence))

    def test_parser_uses_image_evidence_and_keeps_raw_ocr_unchanged(self):
        originals = [FIRST, SECOND, THIRD]
        image, lines = draw_rows(originals)
        noisy = [text.replace("<", "K") for text in originals]
        for line, text in zip(lines, noisy):
            line["text"] = text
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "mrz.png"
            cv2.imwrite(str(path), image)

            result = parse_mrz(lines, image_path=path)

        self.assertEqual(result["normalized_lines"], originals)
        self.assertEqual(result["canonical_text"], "\n".join(originals))
        self.assertEqual([line["text"] for line in result["raw_lines"]], noisy)
        self.assertEqual([line["text"] for line in lines], noisy)
        self.assertTrue(result["format_valid"])
        self.assertFalse(result["checksum_validated"])
        self.assertEqual({entry["operation"] for entry in result["corrections"]}, {"image_verified_filler_ocr_confusion"})

    def test_no_image_keeps_ambiguous_letters_for_review_instead_of_global_replacement(self):
        _, lines = draw_rows([FIRST, SECOND.replace("<", "C"), THIRD.replace("<", "K")])

        result = parse_mrz(lines, image_path=Path("not-present.png"))

        self.assertEqual(result["normalized_lines"][1], lines[1]["text"])
        self.assertEqual(result["status"], "needs_review")
        self.assertIsNone(result["canonical_text"])

    def test_api_normalization_builder_passes_the_processed_image_to_mrz_correction(self):
        image, lines = draw_rows([FIRST, SECOND, THIRD])
        for line in lines:
            line["text"] = line["text"].replace("<", "C")
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            image_path = output / "document.png"
            cv2.imwrite(str(image_path), image)
            with patch("structured_extraction.detect_and_crop_face", return_value={"status": "not_detected"}):
                result = build_structured_result({"lines": lines}, image_path, "mrz", output)

        self.assertEqual(result["mrz"]["normalized_lines"], [FIRST, SECOND, THIRD])
        self.assertEqual(result["mrz"]["status"], "format_valid")
        self.assertEqual([line["text"] for line in result["all_ocr_lines"]], [line["text"] for line in lines])


if __name__ == "__main__":
    unittest.main()
