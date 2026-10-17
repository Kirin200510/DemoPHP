import unittest

from document_pairing import detect_cccd_side, match_citizen_identity_card_sides


def field(value):
    return {"normalized_value": value}


def front_result(number="123456789012"):
    return {
        "document": {"document_type": "citizen_identity_card"},
        "fields": {
            "cccd_number": field(number),
            "full_name": field("Person"),
            "date_of_birth": field("01/01/1990"),
            "sex": field("Nam"),
        },
    }


def back_result(number="123456789012"):
    return {
        "document": {"document_type": "citizen_identity_card"},
        "fields": {
            "mrz_prefix_9": field(number[3:]),
            "mrz_document_number": field(number),
            "place_of_birth": field("Place"),
            "issue_date": field("01/01/2025"),
            "expiry_date": field("01/01/2035"),
        },
    }


class DocumentPairingTest(unittest.TestCase):
    def test_ignores_materialized_but_not_detected_fields_when_detecting_a_side(self):
        result = front_result()
        result["fields"]["place_of_birth"] = {
            "normalized_value": None,
            "extraction_status": "not_detected",
        }
        result["fields"]["issue_date"] = {
            "normalized_value": None,
            "extraction_status": "not_detected",
        }

        self.assertEqual(detect_cccd_side(result)["side"], "front")

    def test_detects_each_side_from_structured_fields(self):
        self.assertEqual(detect_cccd_side(front_result())["side"], "front")
        self.assertEqual(detect_cccd_side(back_result())["side"], "back")

    def test_matches_only_when_front_number_equals_back_mrz_number(self):
        result = match_citizen_identity_card_sides(front_result(), back_result())

        self.assertEqual(result["status"], "matched")
        self.assertEqual(result["reason"], "cccd_number_matches_mrz_positions")

    def test_matches_new_cccd_personal_identification_number_with_back_mrz(self):
        result = front_result()
        result["fields"].pop("cccd_number")
        result["fields"]["personal_identification_number"] = field("123456789012")

        match = match_citizen_identity_card_sides(result, back_result())

        self.assertEqual(match["status"], "matched")

    def test_rejects_numbers_that_do_not_match(self):
        result = match_citizen_identity_card_sides(front_result(), back_result("999999999999"))

        self.assertEqual(result["status"], "not_matched")
        self.assertEqual(result["reason"], "cccd_number_does_not_match_mrz_positions")

    def test_requests_review_when_the_pair_cannot_be_classified(self):
        result = match_citizen_identity_card_sides(front_result(), front_result())

        self.assertEqual(result["status"], "needs_review")

    def test_rejects_when_only_the_mrz_prefix_check_fails(self):
        back = back_result()
        back["fields"]["mrz_prefix_9"] = field("999999999")

        result = match_citizen_identity_card_sides(front_result(), back)

        self.assertEqual(result["status"], "not_matched")
        self.assertFalse(result["checks"]["mrz_positions_6_14_matches_front_suffix"])
        self.assertTrue(result["checks"]["mrz_positions_16_27_matches_front_number"])


if __name__ == "__main__":
    unittest.main()
