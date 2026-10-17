import copy
import unittest
from pathlib import Path
from unittest.mock import patch

from ocr_labels import CANONICAL_FIELD_LABELS, FIELD_LABELS, clean_ocr_lines, find_label_matches
from structured_extraction import build_structured_result, extract_fields


def line(index, text, y=None):
    y = index * 40 if y is None else y
    return {"index": index, "text": text, "confidence": 0.9, "polygon": [[0, y], [900, y], [900, y + 24], [0, y + 24]]}


class OcrTextCleaningTest(unittest.TestCase):
    def test_every_supported_field_uses_the_shared_dictionary_with_spacing_and_separator_noise(self):
        self.assertEqual(set(FIELD_LABELS), set(CANONICAL_FIELD_LABELS))
        for field, canonical in CANONICAL_FIELD_LABELS.items():
            for variant in (canonical, canonical.upper(), canonical.replace(" ", ""), canonical.replace("/", " | ")):
                with self.subTest(field=field, variant=variant):
                    self.assertIn(field, {match.field for match in find_label_matches(variant)})

    def test_known_noise_is_cleaned_without_rewriting_the_value(self):
        cases = (
            ("Personal identificationnumber sentine: 123456789012", "personal_identification_number", "123456789012"),
            ("Nơi cư bũ/Plateofresidence: Xóm 2, Bình An", "residence", "Xóm 2, Bình An"),
            ("Date of binh: 12/03/1990", "date_of_birth", "12/03/1990"),
            ("Dale ofexpity: 12/03/2030", "expiry_date", "12/03/2030"),
            ("Chass1snumber: ABC01234", "chassis_number", "ABC01234"),
            ("Enginenumher: XYZ56789", "engine_number", "XYZ56789"),
        )
        for text, field, value in cases:
            with self.subTest(field=field):
                original = [line(0, text)]
                snapshot = copy.deepcopy(original)
                cleaned = clean_ocr_lines(original)[0]
                self.assertEqual(original, snapshot)
                self.assertIn(field, {match["field"] for match in cleaned["label_matches"]})
                self.assertTrue(cleaned["text"].endswith(value))
                self.assertEqual(cleaned["text"], CANONICAL_FIELD_LABELS[field] + ": " + value)
                fields = extract_fields(original, {"document_type": "citizen_identity_card"})
                self.assertEqual(fields[field]["raw_label"], text)
                self.assertEqual(fields[field]["label"], CANONICAL_FIELD_LABELS[field])
                self.assertEqual(fields[field]["normalized_value"], value)

    def test_documented_label_noise_is_cleaned_even_without_a_value(self):
        cases = (
            ("Personal identificationnumber sentine", "personal_identification_number"),
            ("Nơi cư bũ/Plateofresidence", "residence"),
            ("Date of binh", "date_of_birth"),
            ("Dale ofexpity", "expiry_date"),
        )
        for text, field in cases:
            with self.subTest(text=text):
                result = clean_ocr_lines([line(0, text)])[0]
                self.assertEqual(result["text"], CANONICAL_FIELD_LABELS[field])
                self.assertEqual(result["label_matches"][0]["raw_label"], text)
                self.assertEqual(clean_ocr_lines([result]), [result])

    def test_label_noise_suffix_is_shared_but_never_removed_from_values(self):
        for field, canonical in CANONICAL_FIELD_LABELS.items():
            with self.subTest(field=field):
                text = canonical + " sentine: SENTINE, Bình An"
                result = clean_ocr_lines([line(0, text)])[0]
                self.assertEqual(result["text"], canonical + ": SENTINE, Bình An")

    def test_specific_labels_do_not_leak_into_related_fields(self):
        cases = (
            ("Date of issue: 12/03/2020", "issue_date", {"date_of_birth", "expiry_date", "date_month_year"}),
            ("Dale ofexpity: 12/03/2030", "expiry_date", {"date_of_birth", "issue_date", "date_month_year"}),
            ("Place of binth: Phường A", "place_of_birth", {"date_of_birth", "place_of_origin"}),
            ("Personal identificationnumber: 123456789012", "personal_identification_number", {"personal_identification"}),
            ("Owner's full name: NGUYEN VAN AN", "owner_name", {"full_name"}),
        )
        for text, expected, excluded in cases:
            with self.subTest(text=text):
                fields = extract_fields([line(0, text)], None)
                self.assertIn(expected, fields)
                self.assertTrue(excluded.isdisjoint(fields))

    def test_unknown_text_names_and_addresses_are_not_globally_corrected(self):
        for text in ("NGUYỄN BÌNH AN", "12 Bình An, Phường B", "123456789012", "abcdefghi", "Binh Expity K0"):
            self.assertEqual(clean_ocr_lines([line(0, text)])[0]["text"], text)

    def test_dictionary_words_inside_values_are_not_corrected_as_labels(self):
        for text, value in (
            ("Họ và tên: SƠ NÔ", "SƠ NÔ"),
            ("Địa chỉ: Brand Street, Class A", "Brand Street, Class A"),
            ("Full name SƠ NÔ", "SƠ NÔ"),
        ):
            with self.subTest(text=text):
                cleaned = clean_ocr_lines([line(0, text)])[0]
                self.assertTrue(cleaned["text"].endswith(value))

    def test_multiple_explicit_labels_on_one_row_are_still_recognized(self):
        matches = find_label_matches("Sex: Nam Nationality: Việt Nam")
        self.assertEqual({match.field for match in matches}, {"sex", "nationality"})

    def test_bilingual_label_after_another_value_does_not_require_a_second_colon(self):
        text = "Giới tính/Sex: Nam Quốc tịch/Nationality Việt Nam"
        fields = extract_fields([line(0, text)], None)
        self.assertEqual(fields["sex"]["normalized_value"], "Nam")
        self.assertEqual(fields["nationality"]["normalized_value"], "Việt Nam")

    def test_full_result_keeps_every_raw_line_and_exposes_cleanup_separately(self):
        original = [line(0, "Date of binh: 12/03/1990"), line(1, "Additional unknown content")]
        raw = {"lines": original}
        with patch("structured_extraction.detect_and_crop_face", return_value={"status": "not_detected"}):
            result = build_structured_result(raw, Path("unused.jpg"), "test", Path("unused"))
        self.assertEqual([item["text"] for item in result["all_ocr_lines"]], [item["text"] for item in original])
        self.assertEqual(result["text_cleaning"]["lines"][0]["raw_text"], original[0]["text"])
        self.assertEqual(result["unmapped_raw_lines"][0]["text"], "Additional unknown content")
        self.assertEqual(result["fields"]["date_of_birth"]["normalized_value"], "12/03/1990")

    def test_normalization_pipeline_returns_clean_labels_and_mrz_with_original_evidence(self):
        mrz_lines = [
            "IDVNM1234567890123456789012<<1",
            "9001011M3001019VNM" + "<" * 11 + "0",
            "MACK<<VAN<AN".ljust(30, "<"),
        ]
        original = [line(0, "Nơi cư bũ/Plateofresidence: Xóm 2, Bình An")]
        original += [line(index + 1, text + "CC0K") for index, text in enumerate(mrz_lines)]
        with patch("structured_extraction.detect_and_crop_face", return_value={"status": "not_detected"}):
            result = build_structured_result({"lines": original}, Path("unused.jpg"), "test", Path("unused"))
        self.assertEqual(result["fields"]["residence"]["normalized_value"], "Xóm 2, Bình An")
        self.assertEqual(result["text_cleaning"]["lines"][0]["normalized_text"], CANONICAL_FIELD_LABELS["residence"] + ": Xóm 2, Bình An")
        self.assertEqual(result["mrz"]["normalized_lines"], mrz_lines)
        self.assertEqual(result["mrz"]["canonical_text"], "\n".join(mrz_lines))
        self.assertEqual([item["text"] for item in result["all_ocr_lines"]], [item["text"] for item in original])


if __name__ == "__main__":
    unittest.main()
