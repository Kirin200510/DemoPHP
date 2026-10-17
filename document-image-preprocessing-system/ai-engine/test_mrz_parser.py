import copy
import unittest

from mrz_parser import parse_mrz


def row(index, text):
    return {"index": index, "text": text, "confidence": 0.9, "polygon": [[0, index * 45], [900, index * 45], [900, index * 45 + 30], [0, index * 45 + 30]]}


FIRST = "IDVNM" + "123456789" + "0" + "123456789012" + "<<1"
SECOND = "9001011M3001019VNM" + "<" * 11 + "0"
THIRD = "MACK<<VAN<AN".ljust(30, "<")


class MrzParserTest(unittest.TestCase):
    def test_complete_td1_retains_valid_c_k_and_final_zero_without_claiming_checksum_validation(self):
        original = [row(0, FIRST), row(1, SECOND), row(2, THIRD)]
        snapshot = copy.deepcopy(original)
        result = parse_mrz(original)
        self.assertEqual(original, snapshot)
        self.assertTrue(result["format_valid"])
        self.assertFalse(result["checksum_validated"])
        self.assertEqual(result["normalized_lines"], [FIRST, SECOND, THIRD])
        self.assertEqual(result["identifier"]["document_number"], "123456789012")

    def test_case_spacing_and_document_code_correction_are_traced(self):
        raw_first = "1" + FIRST[1:]
        result = parse_mrz([row(0, raw_first.lower()), row(1, SECOND), row(2, THIRD)])
        self.assertEqual(result["normalized_lines"][0], FIRST)
        self.assertEqual(result["raw_lines"][0]["text"], raw_first.lower())
        self.assertEqual(len(result["corrections"]), 2)

    def test_missing_rows_and_missing_digits_are_not_filled_to_thirty_characters(self):
        result = parse_mrz([row(0, FIRST[:27])])
        self.assertEqual(result["status"], "needs_review")
        self.assertEqual(len(result["normalized_lines"][0]), 27)
        self.assertIn("incomplete_mrz", result["warnings"])

    def test_filler_noise_has_a_review_candidate_and_preserves_the_original_final_digit(self):
        noisy = "9001011M3001019VNM" + "C" * 11 + "0"
        result = parse_mrz([row(0, FIRST), row(1, noisy), row(2, THIRD)])
        self.assertEqual(result["status"], "needs_review")
        self.assertEqual(result["normalized_lines"][1], noisy)
        self.assertEqual(result["candidate_lines"][1]["text"], SECOND)
        self.assertTrue(result["candidate_lines"][1]["requires_review"])

    def test_surplus_name_padding_is_trimmed_with_a_trace(self):
        result = parse_mrz([row(0, FIRST), row(1, SECOND), row(2, THIRD + "CC")])
        self.assertTrue(result["format_valid"])
        self.assertEqual(result["normalized_lines"][2], THIRD)
        self.assertEqual(result["raw_lines"][2]["text"], THIRD + "CC")
        self.assertEqual(result["candidate_lines"][2]["text"], THIRD)
        self.assertEqual(result["corrections"][-1]["operation"], "remove_surplus_padding")

    def test_review_candidate_trims_overlong_optional_filler_but_keeps_the_final_check_digit(self):
        noisy = "9001011M3001019VNM" + "C" * 19 + "0"
        result = parse_mrz([row(0, FIRST), row(1, noisy), row(2, THIRD)])
        self.assertEqual(result["normalized_lines"][1], "9001011M3001019VNM" + "C" * 11 + "0")
        self.assertEqual(result["candidate_lines"][1]["text"], SECOND)
        self.assertFalse(result["format_valid"])

    def test_surplus_padding_characters_are_removed_from_each_row_without_removing_check_digits(self):
        for suffix in ("0", "K", "CCCCC", "<0KCC<<"):
            with self.subTest(suffix=suffix):
                result = parse_mrz([row(0, FIRST + suffix), row(1, SECOND + suffix), row(2, THIRD + suffix)])
                self.assertEqual(result["normalized_lines"], [FIRST, SECOND, THIRD])
                self.assertEqual(result["canonical_text"], "\n".join((FIRST, SECOND, THIRD)))
                self.assertFalse(result["checksum_validated"])

    def test_unknown_surplus_is_not_deleted_or_reported_as_canonical(self):
        result = parse_mrz([row(0, FIRST), row(1, SECOND), row(2, THIRD + "AN")])
        self.assertEqual(result["normalized_lines"][2], THIRD + "AN")
        self.assertEqual(result["status"], "needs_review")
        self.assertIsNone(result["canonical_text"])

    def test_valid_optional_data_and_long_names_are_not_removed(self):
        second = SECOND[:18] + "ABC012K3456" + "0"
        third = "MACK<<KAC".ljust(30, "<")
        result = parse_mrz([row(0, FIRST), row(1, second), row(2, third)])
        self.assertEqual(result["normalized_lines"], [FIRST, second, third])
        self.assertTrue(result["format_valid"])

    def test_zero_confusion_inside_padding_is_reviewed_not_confused_with_final_digit(self):
        noisy = SECOND[:18] + "<00<<KKCCC<" + "0"
        result = parse_mrz([row(0, FIRST), row(1, noisy), row(2, THIRD)])
        self.assertEqual(result["normalized_lines"][1], noisy)
        self.assertEqual(result["candidate_lines"][1]["text"], SECOND)
        self.assertEqual(result["status"], "needs_review")

    def test_multiple_headers_are_ambiguous_and_no_identifier_is_selected(self):
        for lines in ([row(0, FIRST), row(1, FIRST)], [row(0, FIRST + FIRST)]):
            result = parse_mrz(lines)
            self.assertIsNone(result["identifier"])
            self.assertIn("multiple_mrz_headers", result["warnings"])

    def test_split_ocr_fragments_are_joined_using_layout(self):
        first_half, last_half = row(0, FIRST[:15]), row(1, FIRST[15:])
        first_half["polygon"] = [[0, 0], [440, 0], [440, 30], [0, 30]]
        last_half["polygon"] = [[450, 0], [900, 0], [900, 30], [450, 30]]
        result = parse_mrz([first_half, last_half, row(2, SECOND), row(3, THIRD)])
        self.assertEqual(result["normalized_lines"][0], FIRST)
        self.assertEqual(result["identifier"]["source_line_indexes"], [0, 1])


if __name__ == "__main__":
    unittest.main()
