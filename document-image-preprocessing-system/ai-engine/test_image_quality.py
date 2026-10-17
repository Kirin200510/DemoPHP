"""Kiểm thử đơn vị cho bước từ chối sớm ảnh không thể xử lý."""

import tempfile
import unittest
from pathlib import Path

import cv2
import numpy as np

from image_quality import assess_image_quality


class ImageQualityTest(unittest.TestCase):
    def write_image(self, image: np.ndarray) -> Path:
        temporary_file = tempfile.NamedTemporaryFile(suffix=".jpg", delete=False)
        temporary_file.close()
        path = Path(temporary_file.name)
        self.assertTrue(cv2.imwrite(str(path), image))
        self.addCleanup(path.unlink, missing_ok=True)

        return path

    def test_rejects_an_image_without_usable_detail(self) -> None:
        image = np.full((500, 800, 3), 245, dtype=np.uint8)

        result = assess_image_quality(self.write_image(image))

        self.assertFalse(result.accepted)
        self.assertEqual("image_too_blurry_or_low_contrast", result.code)

    def test_accepts_a_document_like_image_with_a_bright_background(self) -> None:
        image = np.full((600, 900, 3), 245, dtype=np.uint8)
        cv2.rectangle(image, (120, 100), (780, 500), (40, 40, 40), 5)
        cv2.putText(
            image,
            "DOCUMENT 0123456789",
            (180, 290),
            cv2.FONT_HERSHEY_SIMPLEX,
            1.0,
            (30, 30, 30),
            2,
            cv2.LINE_AA,
        )

        result = assess_image_quality(self.write_image(image))

        self.assertTrue(result.accepted)
        self.assertIsNone(result.code)

    def test_rejects_a_detailed_photo_without_a_document_frame(self) -> None:
        image = np.zeros((600, 900, 3), dtype=np.uint8)
        random_generator = np.random.default_rng(42)
        image[:, :, :] = random_generator.integers(
            0,
            255,
            size=image.shape,
            dtype=np.uint8,
        )

        result = assess_image_quality(self.write_image(image))

        self.assertFalse(result.accepted)
        self.assertEqual("document_frame_not_detected", result.code)
        self.assertLess(result.document_frame_score, 0.12)


if __name__ == "__main__":
    unittest.main()
