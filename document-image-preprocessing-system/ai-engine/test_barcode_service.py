import base64
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import cv2
import numpy as np
import zxingcpp

from barcode_service import _read_barcode_region, decode_document_barcodes, parse_identity_payload
from structured_extraction import build_structured_result


class BarcodeServiceTest(unittest.TestCase):
    def test_binary_pdf417_preserves_all_byte_values_without_inventing_identity_fields(self):
        raw = bytes(range(256))
        barcode = zxingcpp.create_barcode(raw, zxingcpp.BarcodeFormat.PDF417)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "binary.png"
            cv2.imwrite(str(path), np.asarray(barcode.to_image(scale=4)))

            result = decode_document_barcodes(path)

        self.assertEqual(result["status"], "decoded")
        self.assertEqual(len(result["records"]), 1)
        record = result["records"][0]
        self.assertEqual(base64.b64decode(record["raw_bytes_base64"]), raw)
        self.assertEqual(record["content_type"], "Binary")
        self.assertEqual(record["byte_length"], 256)
        self.assertTrue(record["symbology_identifier"].startswith("]L"))
        self.assertEqual(record["parsed"], {"status": "unsupported", "profile": None, "fields": {}})

    def test_low_resolution_top_pdf417_region_can_be_decoded_after_upscaling(self):
        raw = b'{"cccd_number":"012345678901"}'
        barcode = zxingcpp.create_barcode(raw, zxingcpp.BarcodeFormat.PDF417)
        barcode_image = np.asarray(barcode.to_image(scale=1))
        card = np.full((430, 683), 255, np.uint8)
        height, width = barcode_image.shape[:2]
        card[:height, :width] = barcode_image

        decoded = _read_barcode_region(card)

        self.assertEqual([(item.format.name, bytes(item.bytes)) for item in decoded], [("PDF417", raw)])

    def test_local_pdf417_is_decoded_and_keyed_identity_fields_are_parsed(self):
        raw = json.dumps({"cccd_number": "012345678901", "full_name": "NGUYEN VAN AN", "date_of_birth": "1990-01-02"}).encode()
        barcode = zxingcpp.create_barcode(raw, zxingcpp.BarcodeFormat.PDF417)
        image = np.asarray(barcode.to_image(scale=4))
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "back.png"
            cv2.imwrite(str(path), image)

            result = decode_document_barcodes(path)

        record = result["records"][0]
        self.assertEqual(record["format"], "PDF417")
        self.assertEqual(base64.b64decode(record["raw_bytes_base64"]), raw)
        self.assertEqual(record["parsed"]["fields"]["personal_identification_number"], "012345678901")
        self.assertEqual(record["parsed"]["fields"]["date_of_birth"], "02/01/1990")

    def test_arbitrary_text_binary_and_invalid_fields_do_not_become_identity_evidence(self):
        for raw in (b"invoice 012345678901 total 42", b"\xff\x00\x01", b'{"cccd_number":123456789012}', b'{"cccd_number":"012345678901","date_of_birth":"31/02/1990"}'):
            with self.subTest(raw=raw):
                self.assertEqual(parse_identity_payload(raw, "PDF417")["status"], "unsupported")

    def test_number_only_payload_keeps_leading_zero(self):
        self.assertEqual(parse_identity_payload(b"012345678901", "PDF417")["fields"], {"personal_identification_number": "012345678901"})

    def test_duplicate_keys_and_invalid_auxiliary_fields_are_not_trusted(self):
        for payload in (
            '{"cccd_number":"012345678901","cccd_number":"999999999999"}',
            '{"cccd_number":"012345678901","sex":"unknown"}',
            '{"cccd_number":"012345678901","full_name":"12345"}',
            '{"cccd_number":"012345678901","date_of_birth":19900102}',
        ):
            with self.subTest(payload=payload):
                self.assertEqual(parse_identity_payload(payload.encode(), "PDF417")["status"], "unsupported")

    def test_recognized_qr_layout_is_validated_and_dates_are_normalized(self):
        raw = "012345678901||Nguyễn Văn An|02011990|Nam|Phường A|01012025".encode()

        result = parse_identity_payload(raw, "QRCode")

        self.assertEqual(result["profile"], "vn_identity_qr_pipe_7")
        self.assertEqual(result["fields"]["date_of_birth"], "02/01/1990")
        self.assertEqual(result["fields"]["issue_date"], "01/01/2025")
        self.assertEqual(parse_identity_payload(raw, "PDF417")["status"], "unsupported")

    def test_original_image_is_scanned_when_processed_barcode_is_unreadable(self):
        barcode = zxingcpp.create_barcode("012345678901", zxingcpp.BarcodeFormat.PDF417)
        with tempfile.TemporaryDirectory() as directory:
            processed, original = Path(directory) / "processed.png", Path(directory) / "original.png"
            cv2.imwrite(str(processed), np.full((200, 800), 255, np.uint8))
            cv2.imwrite(str(original), np.asarray(barcode.to_image(scale=4)))

            result = decode_document_barcodes(processed, original)

        self.assertEqual(len(result["records"]), 1)
        self.assertEqual(result["records"][0]["source_stage"], "original")

    def test_matching_barcode_on_original_and_processed_is_not_duplicated(self):
        barcode = zxingcpp.create_barcode("012345678901", zxingcpp.BarcodeFormat.PDF417)
        with tempfile.TemporaryDirectory() as directory:
            first, second = Path(directory) / "a.png", Path(directory) / "b.png"
            for path in (first, second):
                cv2.imwrite(str(path), np.asarray(barcode.to_image(scale=4)))

            result = decode_document_barcodes(first, second)

        self.assertEqual(len(result["records"]), 1)

    def test_decoder_failure_does_not_discard_successful_ocr(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "image.png"
            cv2.imwrite(str(path), np.full((100, 200), 255, np.uint8))
            with patch("barcode_service.zxingcpp.read_barcodes", side_effect=RuntimeError("decoder failed")):
                result = decode_document_barcodes(path)

        self.assertEqual(result["warnings"], ["barcode_decoder_failed"])
        self.assertEqual(result["records"], [])

    def test_normalization_pipeline_retains_unknown_payload_without_assigning_ocr_fields(self):
        barcode = zxingcpp.create_barcode("unknown 012345678901", zxingcpp.BarcodeFormat.PDF417)
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            path = output / "image.png"
            cv2.imwrite(str(path), np.asarray(barcode.to_image(scale=4)))
            with patch("structured_extraction.detect_and_crop_face", return_value={"status": "not_detected"}):
                result = build_structured_result({"lines": []}, path, "barcode", output)

        self.assertEqual(result["barcodes"]["records"][0]["parsed"]["status"], "unsupported")
        self.assertEqual(result["fields"], {})


if __name__ == "__main__":
    unittest.main()
