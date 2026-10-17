"""Kiểm thử model OCR chỉ khởi tạo một lần trong tiến trình FastAPI."""

import unittest
from unittest.mock import sentinel, patch

import ocr_service


class OcrModelCacheTest(unittest.TestCase):
    def setUp(self) -> None:
        self.previous_detector = ocr_service._detector
        ocr_service._detector = None

    def tearDown(self) -> None:
        ocr_service._detector = self.previous_detector

    def test_detector_is_reused_after_first_initialization(self) -> None:
        with patch(
            "ocr_service.create_model",
            return_value=sentinel.detector,
        ) as create_model:
            first_detector = ocr_service.get_detector()
            second_detector = ocr_service.get_detector()

        self.assertIs(sentinel.detector, first_detector)
        self.assertIs(first_detector, second_detector)
        create_model.assert_called_once_with(
            ocr_service.OCR_DETECTOR,
            device="cpu",
            engine="onnxruntime",
        )


if __name__ == "__main__":
    unittest.main()
