import unittest

from structured_extraction import (
    apply_citizen_identity_card_schema,
    build_citizen_identity_card_pair_result,
    clear_conflicting_birth_date,
    extract_fields,
    infer_document_from_fields,
    materialize_citizen_identity_card_fixed_fields,
)


def field(value, source_line):
    return {
        "label": "label",
        "raw_value": value,
        "normalized_value": value,
        "confidence": 0.9,
        "source_line_indexes": [source_line],
    }


class CitizenIdentityCardPairExtractionTest(unittest.TestCase):
    def test_assigns_new_cccd_fixed_schema(self):
        document = {"document_type": "citizen_identity_card"}
        fields = {
            "personal_identification_number": field("123456789012", 1),
            "place_of_birth": field("Place", 2),
            "issue_date": field("01/01/2025", 3),
        }

        result = apply_citizen_identity_card_schema(document, fields)

        self.assertEqual(result["card_version"], "new")
        self.assertEqual(result["fixed_field_names"][0], "personal_identification_number")
        self.assertIn("expiry_date", result["fixed_field_names"])

    def test_assigns_legacy_cccd_fixed_schema(self):
        document = {"document_type": "citizen_identity_card"}
        fields = {"cccd_number": field("123456789012", 1)}

        result = apply_citizen_identity_card_schema(document, fields)

        self.assertEqual(result["card_version"], "legacy")
        self.assertIn("personal_identification", result["fixed_field_names"])
        self.assertIn("date_month_year", result["fixed_field_names"])

    def test_materializes_missing_fixed_cccd_fields_without_creating_values(self):
        document = apply_citizen_identity_card_schema(
            {"document_type": "citizen_identity_card"},
            {"cccd_number": field("123456789012", 1)},
        )
        fields = {"cccd_number": field("123456789012", 1)}

        materialize_citizen_identity_card_fixed_fields(document, fields)

        self.assertIsNone(fields["personal_identification"]["normalized_value"])
        self.assertEqual(
            fields["personal_identification"]["extraction_status"],
            "not_detected",
        )

    def test_recovers_a_single_unlabelled_12_digit_cccd_number(self):
        lines = [
            {
                "index": 0,
                "text": "123456789012",
                "confidence": 0.9,
                "polygon": [[0, 0], [220, 0], [220, 20], [0, 20]],
            },
        ]
        document = {"document_type": "citizen_identity_card"}

        fields = extract_fields(lines, document)

        self.assertEqual(fields["cccd_number"]["normalized_value"], "123456789012")
        self.assertEqual(
            fields["cccd_number"]["label"],
            "Số/No (fallback chuỗi CCCD 12 chữ số)",
        )

    def test_identifies_cccd_back_from_its_distinctive_labels(self):
        lines = [
            {
                "index": 0,
                "text": "Nơi đăng ký khai sinh / Place of birth: Phường A",
                "confidence": 0.9,
                "polygon": [[0, 0], [500, 0], [500, 20], [0, 20]],
            },
            {
                "index": 1,
                "text": "Ngày, tháng, năm cấp / Date of issue: 01/01/2025",
                "confidence": 0.9,
                "polygon": [[0, 40], [550, 40], [550, 60], [0, 60]],
            },
            {
                "index": 2,
                "text": "Ngày, tháng, năm hết hạn / Date of expiry: 01/01/2035",
                "confidence": 0.9,
                "polygon": [[0, 80], [600, 80], [600, 100], [0, 100]],
            },
        ]

        fields = extract_fields(lines, None)
        document = infer_document_from_fields(fields)

        self.assertEqual(document["document_type"], "citizen_identity_card")
        self.assertEqual(fields["place_of_birth"]["normalized_value"], "Phường A")
        self.assertEqual(fields["issue_date"]["normalized_value"], "01/01/2025")
        self.assertEqual(fields["expiry_date"]["normalized_value"], "01/01/2035")

    def test_extracts_new_cccd_fixed_fields_from_their_labels(self):
        lines = [
            {
                "index": 0,
                "text": "Số định danh cá nhân/Personal identification number: 123456789012",
                "confidence": 0.9,
                "polygon": [[0, 0], [650, 0], [650, 20], [0, 20]],
            },
            {
                "index": 1,
                "text": "Nơi đăng ký khai sinh/Place of birth: Phường A",
                "confidence": 0.9,
                "polygon": [[0, 40], [600, 40], [600, 60], [0, 60]],
            },
            {
                "index": 2,
                "text": "Ngày, tháng, năm cấp/Date of issue: 01/01/2025",
                "confidence": 0.9,
                "polygon": [[0, 80], [620, 80], [620, 100], [0, 100]],
            },
        ]
        document = {"document_type": "citizen_identity_card"}

        fields = extract_fields(lines, document)
        schema = apply_citizen_identity_card_schema(document, fields)

        self.assertEqual(
            fields["personal_identification_number"]["normalized_value"],
            "123456789012",
        )
        self.assertNotIn("cccd_number", fields)
        self.assertEqual(fields["place_of_birth"]["normalized_value"], "Phường A")
        self.assertEqual(fields["issue_date"]["normalized_value"], "01/01/2025")
        self.assertEqual(schema["card_version"], "new")

    def test_extracts_residence_when_ocr_corrupts_the_vietnamese_label(self):
        lines = [
            {
                "index": 0,
                "text": "Nơi cư bũ/Plateofresidence Xóm 2, Thôn 2",
                "confidence": 0.9,
                "polygon": [[0, 0], [600, 0], [600, 25], [0, 25]],
            },
            {
                "index": 1,
                "text": "Bắc Ruộng, Tánh Linh, Bình Thuận",
                "confidence": 0.9,
                "polygon": [[0, 30], [600, 30], [600, 55], [0, 55]],
            },
        ]

        fields = extract_fields(lines, {"document_type": "citizen_identity_card"})

        self.assertEqual(
            fields["residence"]["normalized_value"],
            "Xóm 2, Thôn 2 Bắc Ruộng, Tánh Linh, Bình Thuận",
        )

    def test_keeps_new_cccd_date_and_sex_values_in_their_fixed_fields(self):
        def line(index, text, x, y, width=260):
            return {
                "index": index,
                "text": text,
                "confidence": 0.9,
                "polygon": [[x, y], [x + width, y], [x + width, y + 24], [x, y + 24]],
            }

        lines = [
            line(0, "CĂN CƯỚC", 20, 20),
            line(1, "Ngày, tháng, năm sinh/Date of binh", 20, 120, 420),
            line(2, "Giới tính/Sex", 520, 120),
            line(3, "Nam", 520, 155, 90),
            line(4, "25/10/1984", 20, 155, 180),
            line(5, "Quốc tịch/Nationality", 20, 195, 300),
            line(6, "Việt Nam", 20, 230, 160),
        ]

        document = {"document_type": "citizen_identity_card"}
        fields = extract_fields(lines, document)

        self.assertEqual(fields["date_of_birth"]["normalized_value"], "25/10/1984")
        self.assertEqual(fields["sex"]["normalized_value"], "Nam")
        self.assertEqual(fields["nationality"]["normalized_value"], "Việt Nam")
        self.assertNotIn("issue_date", fields)

    def test_does_not_assign_issue_or_expiry_dates_as_a_birth_date(self):
        lines = [
            {
                "index": 0,
                "text": "Ngày, tháng, năm cấp/Date of issue: 01/01/2025",
                "confidence": 0.9,
                "polygon": [[0, 0], [620, 0], [620, 20], [0, 20]],
            },
            {
                "index": 1,
                "text": "Ngày, tháng, năm hết hạn/Date of expiry: 01/01/2035",
                "confidence": 0.9,
                "polygon": [[0, 40], [650, 40], [650, 60], [0, 60]],
            },
        ]

        fields = extract_fields(lines, {"document_type": "citizen_identity_card"})

        self.assertNotIn("date_of_birth", fields)
        self.assertEqual(fields["issue_date"]["normalized_value"], "01/01/2025")
        self.assertEqual(fields["expiry_date"]["normalized_value"], "01/01/2035")

    def test_does_not_assign_a_generic_date_to_a_specific_fixed_field(self):
        lines = [
            {
                "index": 0,
                "text": "Ngày, tháng, năm: 01/01/2025",
                "confidence": 0.9,
                "polygon": [[0, 0], [420, 0], [420, 20], [0, 20]],
            },
        ]

        fields = extract_fields(lines, {"document_type": "citizen_identity_card"})

        self.assertNotIn("date_of_birth", fields)
        self.assertNotIn("issue_date", fields)
        self.assertNotIn("expiry_date", fields)
        self.assertEqual(fields["date_month_year"]["normalized_value"], "01/01/2025")

    def test_does_not_assign_a_value_when_the_label_semantics_do_not_match(self):
        lines = [
            {
                "index": 0,
                "text": "Ngày, tháng, năm cấp/Date of issue: Nam",
                "confidence": 0.9,
                "polygon": [[0, 0], [620, 0], [620, 20], [0, 20]],
            },
            {
                "index": 1,
                "text": "Quê quán/Place of origin: 01/01/2025",
                "confidence": 0.9,
                "polygon": [[0, 40], [620, 40], [620, 60], [0, 60]],
            },
        ]

        fields = extract_fields(lines, {"document_type": "citizen_identity_card"})

        self.assertNotIn("issue_date", fields)
        self.assertNotIn("date_of_birth", fields)
        self.assertNotIn("place_of_origin", fields)

    def test_clears_a_fuzzy_birth_date_assignment_when_source_label_is_expiry(self):
        lines = [
            {
                "index": 0,
                "text": "Ngày, tháng, năm het han/Date ofexpiry: 09/10/2035",
                "confidence": 0.9,
                "polygon": [[0, 0], [650, 0], [650, 20], [0, 20]],
            },
        ]

        fields = {
            "date_of_birth": field("09/10/2035", 0),
            "expiry_date": field("09/10/2035", 0),
        }
        fields["date_of_birth"]["label"] = lines[0]["text"]

        clear_conflicting_birth_date(fields)

        self.assertIsNone(fields["date_of_birth"]["normalized_value"])
        self.assertEqual(fields["date_of_birth"]["source_line_indexes"], [])

    def test_detects_new_cccd_title_without_the_legacy_citizen_wording(self):
        lines = [
            {
                "index": 0,
                "text": "CĂN CƯỚC",
                "confidence": 0.9,
                "polygon": [[0, 0], [300, 0], [300, 20], [0, 20]],
            },
        ]

        from structured_extraction import extract_document_title

        document = extract_document_title(lines)

        self.assertIsNotNone(document)
        self.assertEqual(document["document_type"], "citizen_identity_card")

    def test_extracts_the_trailing_twelve_digit_number_from_new_cccd_mrz(self):
        lines = [
            {
                "index": 0,
                "text": "IDVNM1234567890678901234567<1",
                "confidence": 0.9,
                "polygon": [[0, 0], [800, 0], [800, 20], [0, 20]],
            },
        ]

        fields = extract_fields(lines, None)

        self.assertEqual(
            fields["mrz_document_number"]["normalized_value"],
            "678901234567",
        )
        self.assertEqual(
            fields["mrz_prefix_9"]["normalized_value"],
            "123456789",
        )

    def test_merges_back_fields_without_overriding_front_fields(self):
        front = {
            "document": {"document_type": "citizen_identity_card"},
            "fields": {
                "cccd_number": field("123456789012", 1),
                "full_name": field("Nguyen Van A", 2),
                "residence": field("Dia chi mat truoc", 3),
            },
            "face_crop": {"status": "detected", "path": "faces/front.jpg"},
            "verification": {"issuer": None},
            "all_ocr_lines": [{"index": 0, "text": "front field"}],
            "unmapped_raw_lines": [{"index": 9, "text": "front extra"}],
        }
        back = {
            "document": {"document_type": "citizen_identity_card"},
            "fields": {
                "residence": field("Dia chi mat sau", 1),
                "place_of_birth": field("Noi sinh", 2),
                "issue_date": field("01/01/2025", 3),
                "expiry_date": field("01/01/2035", 4),
            },
            "verification": {"issuer": None},
            "all_ocr_lines": [{"index": 0, "text": "back field"}],
            "unmapped_raw_lines": [{"index": 8, "text": "back extra"}],
        }

        result = build_citizen_identity_card_pair_result(front, back, "front.jpeg", "back.jpeg")

        self.assertEqual(result["fields"]["full_name"]["source_side"], "front")
        self.assertEqual(result["fields"]["residence"]["normalized_value"], "Dia chi mat truoc")
        self.assertEqual(result["fields"]["place_of_birth"]["source_side"], "back")
        self.assertEqual(result["fields"]["issue_date"]["normalized_value"], "01/01/2025")
        self.assertNotIn("path", result["sides"]["front"]["face_crop"])
        self.assertFalse(result["sides"]["front"]["face_crop"]["persisted"])
        self.assertEqual(
            [line["source_side"] for line in result["unmapped_raw_lines"]],
            ["front", "back"],
        )
        self.assertEqual(
            [line["source_side"] for line in result["all_ocr_lines"]],
            ["front", "back"],
        )

    def test_rejects_a_pair_when_the_front_is_not_cccd(self):
        with self.assertRaisesRegex(ValueError, "mặt trước"):
            build_citizen_identity_card_pair_result(
                {"document": {"document_type": "driver_license"}},
                {"document": {"document_type": "citizen_identity_card"}},
                "front.jpeg",
                "back.jpeg",
            )


if __name__ == "__main__":
    unittest.main()
