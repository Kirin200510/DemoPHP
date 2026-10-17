import copy
import unittest

from fastapi.testclient import TestClient

import app
from document_pairing import match_citizen_identity_card_sides
from structured_extraction import build_citizen_identity_card_pair_result
from test_document_pairing import back_result, field, front_result


def barcode_back(number="123456789012", **extra):
    return {
        "document": {"document_type": "citizen_identity_card"},
        "fields": {"personal_identification": field("Small scar"), "issue_date": field("01/01/2025")},
        "barcodes": {"records": [{
            "format": "PDF417", "parsed": {"status": "parsed", "profile": "identity_keyed_json", "fields": {"personal_identification_number": number, **extra}},
        }]},
    }


class PairingStage2Test(unittest.TestCase):
    def test_barcode_back_matches_in_reverse_order_and_supplements_only_missing_fields(self):
        front = front_result()
        back = barcode_back(residence="Place from barcode")
        snapshot = copy.deepcopy(back)

        result = match_citizen_identity_card_sides(back, front)
        merged = build_citizen_identity_card_pair_result(result["front_result"], result["back_result"], "front", "back")

        self.assertEqual(result["status"], "matched")
        self.assertEqual(result["matching_method"], "barcode")
        self.assertEqual(merged["fields"]["residence"]["normalized_value"], "Place from barcode")
        self.assertEqual(merged["fields"]["residence"]["source_type"], "barcode")
        self.assertEqual(merged["fields"]["cccd_number"]["normalized_value"], "123456789012")
        self.assertEqual(back, snapshot)

    def test_barcode_identifier_mismatch_is_not_merged(self):
        result = match_citizen_identity_card_sides(front_result(), barcode_back("999999999999"))

        self.assertEqual(result["error"]["code"], "ERR_PAIR_MISMATCH")
        self.assertEqual(result["status"], "not_matched")

    def test_different_consistent_identities_on_each_side_are_a_mismatch_not_a_source_conflict(self):
        front = front_result()
        front["barcodes"] = barcode_back()["barcodes"]
        front["barcodes"]["records"][0]["format"] = "QRCode"
        back = back_result("999999999999")
        back["barcodes"] = barcode_back("999999999999")["barcodes"]

        result = match_citizen_identity_card_sides(front, back)

        self.assertEqual(result["error"]["code"], "ERR_PAIR_MISMATCH")
        self.assertEqual(result["http_status"], 400)

    def test_same_number_with_conflicting_name_birth_or_sex_is_rejected(self):
        for values in ({"full_name": "Different Person"}, {"date_of_birth": "02/01/1990"}, {"sex": "Nữ"}):
            with self.subTest(values=values):
                result = match_citizen_identity_card_sides(front_result(), barcode_back(**values))

                self.assertEqual(result["status"], "not_matched")
                self.assertEqual(result["reason"], "identity_details_conflict")

    def test_unknown_barcode_payload_does_not_produce_a_false_match(self):
        back = barcode_back()
        back["barcodes"]["records"][0]["parsed"] = {"status": "unsupported", "fields": {}}

        result = match_citizen_identity_card_sides(front_result(), back)

        self.assertEqual(result["error"]["code"], "ERR_PAIR_INSUFFICIENT_EVIDENCE")
        self.assertNotIn("front_result", result)

    def test_qr_code_on_front_is_not_used_as_evidence_for_unreadable_back(self):
        front = front_result()
        front["barcodes"] = barcode_back()["barcodes"]
        front["barcodes"]["records"][0]["format"] = "QRCode"
        back = back_result()
        back["fields"].pop("mrz_prefix_9")
        back["fields"].pop("mrz_document_number")

        result = match_citizen_identity_card_sides(front, back)

        self.assertEqual(result["status"], "needs_review")

    def test_conflicting_mrz_and_barcode_are_not_silently_prioritized(self):
        back = back_result()
        back["barcodes"] = barcode_back("999999999999")["barcodes"]

        result = match_citizen_identity_card_sides(front_result(), back)

        self.assertEqual(result["error"]["code"], "ERR_PAIR_CONFLICTING_EVIDENCE")

    def test_conflicting_front_aliases_and_partial_mrz_prefix_need_review(self):
        front = front_result()
        front["fields"]["personal_identification_number"] = field("999999999999")
        self.assertEqual(match_citizen_identity_card_sides(front, back_result())["error"]["code"], "ERR_PAIR_CONFLICTING_EVIDENCE")
        back = barcode_back()
        back["fields"]["mrz_prefix_9"] = field("999999999")
        back["mrz"] = {"format_valid": False}
        self.assertEqual(match_citizen_identity_card_sides(front_result(), back)["error"]["code"], "ERR_PAIR_CONFLICTING_EVIDENCE")

    def test_unreliable_mrz_and_invalid_identifiers_need_review(self):
        back = back_result()
        back["mrz"] = {"format_valid": False}

        result = match_citizen_identity_card_sides(front_result(), back)

        self.assertEqual(result["status"], "needs_review")
        self.assertEqual(match_citizen_identity_card_sides(front_result("abc"), back_result())["status"], "needs_review")

    def test_recognized_mrz_details_must_not_contradict_front(self):
        back = back_result()
        back["mrz"] = {"format_valid": True, "normalized_lines": ["X" * 30, "9101011M3001019VNM" + "<" * 11 + "0", "PERSON<<".ljust(30, "<")]}

        result = match_citizen_identity_card_sides(front_result(), back)

        self.assertEqual(result["status"], "not_matched")
        self.assertFalse(result["checks"]["mrz_date_of_birth_matches_front"])


class PairingApiTest(unittest.TestCase):
    def setUp(self):
        self.client = TestClient(app.app)

    def test_mismatch_returns_400_with_a_business_error_and_no_empty_merged_payload(self):
        response = self.client.post("/document-sides/match", json=[front_result(), back_result("999999999999")])

        self.assertEqual(response.status_code, 400)
        self.assertEqual(response.json()["error"]["code"], "ERR_PAIR_MISMATCH")
        self.assertNotIn("paired_normalized_ocr", response.json())
        self.assertNotIn("999999999999", str(response.json()))

    def test_missing_evidence_and_same_sides_return_distinct_422_errors(self):
        missing = back_result()
        missing["fields"].pop("mrz_prefix_9")
        for pair, code in (([front_result(), front_result()], "ERR_PAIR_SIDE_INVALID"), ([front_result(), missing], "ERR_PAIR_INSUFFICIENT_EVIDENCE")):
            with self.subTest(code=code):
                response = self.client.post("/document-sides/match", json=pair)

                self.assertEqual(response.status_code, 422)
                self.assertEqual(response.json()["error"]["code"], code)
                self.assertNotIn("paired_normalized_ocr", response.json())

    def test_reversed_barcode_pair_returns_200_and_complete_merged_data(self):
        response = self.client.post("/document-sides/match", json=[barcode_back(residence="Barcode address"), front_result()])

        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertEqual(payload["front_input_index"], 1)
        self.assertEqual(payload["back_input_index"], 0)
        self.assertEqual(payload["paired_normalized_ocr"]["fields"]["residence"]["normalized_value"], "Barcode address")

    def test_malformed_nested_data_and_wrong_document_count_return_422_not_500(self):
        for payload in ([], [{"fields": "bad"}, front_result()], [{"barcodes": {"records": ["bad"]}}, front_result()]):
            with self.subTest(payload=payload):
                response = self.client.post("/document-sides/match", json=payload)

                self.assertEqual(response.status_code, 422)


if __name__ == "__main__":
    unittest.main()
