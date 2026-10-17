"""Kiểm thử lựa chọn OCR provider, không nạp model OCR thật."""

import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from ocr_service import (
    FptAiReaderOcrProvider,
    HttpEkcycOcrProvider,
    LocalHybridOcrProvider,
    VnptEkcycOcrProvider,
    get_ocr_provider,
)


class OcrProviderTest(unittest.TestCase):
    def test_uses_local_hybrid_provider_by_default(self) -> None:
        with patch.dict(os.environ, {}, clear=True):
            self.assertIsInstance(get_ocr_provider(), LocalHybridOcrProvider)

    def test_uses_external_ekyc_provider_when_configured(self) -> None:
        with patch.dict(
            os.environ,
            {
                "OCR_PROVIDER": "external_ekyc",
                "EKYC_OCR_URL": "https://ekyc.example.test/ocr",
                "EKYC_OCR_API_KEY": "test-key",
                "EKYC_OCR_TIMEOUT_SECONDS": "45",
            },
            clear=True,
        ):
            provider = get_ocr_provider()

        self.assertIsInstance(provider, HttpEkcycOcrProvider)
        self.assertEqual("https://ekyc.example.test/ocr", provider.url)
        self.assertEqual("test-key", provider.api_key)
        self.assertEqual(45, provider.timeout_seconds)

    def test_external_provider_requires_a_url(self) -> None:
        with patch.dict(os.environ, {"OCR_PROVIDER": "external_ekyc"}, clear=True):
            with self.assertRaisesRegex(RuntimeError, "EKYC_OCR_URL"):
                get_ocr_provider()

    def test_uses_fpt_ai_reader_when_configured(self) -> None:
        with patch.dict(
            os.environ,
            {
                "OCR_PROVIDER": "fpt_ai_reader",
                "FPT_AI_READER_API_KEY": "test-fpt-key",
                "FPT_AI_READER_DOCUMENT_TYPE": "driver_license",
                "FPT_AI_READER_TIMEOUT_SECONDS": "45",
            },
            clear=True,
        ):
            provider = get_ocr_provider()

        self.assertIsInstance(provider, FptAiReaderOcrProvider)
        self.assertEqual("test-fpt-key", provider.api_key)
        self.assertEqual("driver_license", provider.document_type)
        self.assertEqual(45, provider.timeout_seconds)
        self.assertEqual(
            "https://api.fpt.ai/vision/dlr/vnm",
            provider.url,
        )

    def test_request_provider_overrides_the_default_environment_provider(self) -> None:
        with patch.dict(
            os.environ,
            {
                "OCR_PROVIDER": "local_hybrid",
                "FPT_AI_READER_API_KEY": "test-fpt-key",
            },
            clear=True,
        ):
            provider = get_ocr_provider("fpt_ai_reader")

        self.assertIsInstance(provider, FptAiReaderOcrProvider)

    def test_uses_vnpt_ekyc_provider_when_configured(self) -> None:
        with patch.dict(
            os.environ,
            {
                "VNPT_EKYC_OCR_URL": "https://vnpt.example.test/ocr",
                "VNPT_EKYC_OCR_API_KEY": "test-vnpt-key",
                "VNPT_EKYC_OCR_TIMEOUT_SECONDS": "45",
            },
            clear=True,
        ):
            provider = get_ocr_provider("vnpt_ekyc")

        self.assertIsInstance(provider, VnptEkcycOcrProvider)
        self.assertEqual("https://vnpt.example.test/ocr", provider.url)
        self.assertEqual("test-vnpt-key", provider.api_key)
        self.assertEqual(45, provider.timeout_seconds)

    def test_fpt_provider_sends_vendor_headers_and_normalizes_response(self) -> None:
        payload = {
            "errorCode": 0,
            "errorMessage": "",
            "data": [
                {
                    "id": "001234567890",
                    "id_prob": "0.99",
                    "name": "NGUYỄN VĂN A",
                    "name_prob": "0.98",
                    "dob": "01/01/2000",
                    "address": "Hồ Chí Minh",
                    "type": "new",
                }
            ],
        }
        response = unittest.mock.MagicMock()
        response.json.return_value = payload
        temporary_file = tempfile.NamedTemporaryFile(suffix=".jpg", delete=False)
        temporary_file.write(b"image-content")
        temporary_file.close()
        image_path = Path(temporary_file.name)
        self.addCleanup(image_path.unlink, missing_ok=True)
        provider = FptAiReaderOcrProvider(
            api_key="test-fpt-key",
            document_type="id_card",
            timeout_seconds=30,
        )

        with patch("ocr_service.requests.post", return_value=response) as post:
            result = provider.extract(image_path)

        self.assertEqual("FPT.AI Reader", result["engine"]["provider"])
        self.assertEqual(4, result["line_count"])
        self.assertEqual("Số/No: 001234567890", result["lines"][0]["text"])
        self.assertEqual(0.99, result["lines"][0]["confidence"])
        self.assertEqual("test-fpt-key", post.call_args.kwargs["headers"]["api_key"])
        self.assertIn("image", post.call_args.kwargs["files"])

    def test_fpt_provider_rejects_vendor_error_payload(self) -> None:
        provider = FptAiReaderOcrProvider(
            api_key="test-fpt-key",
            document_type="id_card",
            timeout_seconds=30,
        )

        with self.assertRaisesRegex(RuntimeError, "rejected the document"):
            provider.normalize_payload(
                {"errorCode": 3, "errorMessage": "Unable to find ID card"}
            )

    def test_fpt_driver_license_provider_normalizes_class_list(self) -> None:
        provider = FptAiReaderOcrProvider(
            api_key="test-fpt-key",
            document_type="driver_license",
            timeout_seconds=30,
        )

        result = provider.normalize_payload(
            {
                "errorCode": 0,
                "data": [
                    {
                        "id": "790000000000",
                        "name": "NGUYỄN VĂN A",
                        "class": ["A1", "B2"],
                    }
                ],
            }
        )

        self.assertIn("Hạng/Class: A1, B2", result["full_text"])

    def test_external_provider_validates_and_returns_vendor_payload(self) -> None:
        payload = {
            "status": "completed",
            "engine": {"provider": "example-ekyc"},
            "line_count": 1,
            "full_text": "Nguyễn Văn A",
            "lines": [],
        }
        response = unittest.mock.MagicMock()
        response.json.return_value = payload
        temporary_file = tempfile.NamedTemporaryFile(suffix=".jpg", delete=False)
        temporary_file.write(b"image-content")
        temporary_file.close()
        image_path = Path(temporary_file.name)
        self.addCleanup(image_path.unlink, missing_ok=True)
        provider = HttpEkcycOcrProvider(
            "https://ekyc.example.test/ocr",
            "test-key",
            30,
        )

        with patch("ocr_service.requests.post", return_value=response) as post:
            result = provider.extract(image_path)

        self.assertEqual(payload, result)
        self.assertEqual("Bearer test-key", post.call_args.kwargs["headers"]["Authorization"])


if __name__ == "__main__":
    unittest.main()
