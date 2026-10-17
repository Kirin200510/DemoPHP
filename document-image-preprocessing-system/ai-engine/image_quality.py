"""Các phép kiểm tra chất lượng ảnh đầu vào có chi phí thấp.

Mục đích của mô-đun này là từ chối sớm những ảnh không thể phục hồi được,
trước khi khởi động toàn bộ pipeline OCR. Các ngưỡng được đặt bảo thủ để
không loại nhầm ảnh giấy tờ có nền sáng, bị nghiêng hoặc có vùng phản sáng
nhưng vẫn còn nội dung đọc được.
"""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np


MAX_ANALYSIS_EDGE = 1200
MIN_DETAIL_SCORE = 4.0
MIN_CONTRAST_SCORE = 10.0
SEVERE_BRIGHT_RATIO = 0.92
MIN_DOCUMENT_FRAME_SCORE = 0.004
MIN_DOCUMENT_FRAME_AREA_RATIO = 0.005
MAX_DOCUMENT_FRAME_AREA_RATIO = 0.92


@dataclass(frozen=True)
class ImageQualityResult:
    """Kết quả đánh giá ảnh trước pipeline."""

    accepted: bool
    code: str | None
    detail: str | None
    blur_score: float
    contrast_score: float
    bright_ratio: float
    document_frame_score: float = 0.0


def assess_image_quality(image_path: Path) -> ImageQualityResult:
    """Đánh giá xem ảnh còn đủ thông tin tối thiểu để xử lý hay không."""

    image = cv2.imread(str(image_path), cv2.IMREAD_COLOR)

    if image is None:
        return ImageQualityResult(
            accepted=False,
            code="invalid_image",
            detail="Uploaded file could not be decoded as an image.",
            blur_score=0.0,
            contrast_score=0.0,
            bright_ratio=0.0,
        )

    analysis_image = resize_for_analysis(image)
    gray_image = cv2.cvtColor(analysis_image, cv2.COLOR_BGR2GRAY)
    blur_score = float(cv2.Laplacian(gray_image, cv2.CV_64F).var())
    contrast_score = float(gray_image.std())
    bright_ratio = float(np.mean(gray_image >= 250))

    is_low_detail = (
        blur_score < MIN_DETAIL_SCORE
        and contrast_score < MIN_CONTRAST_SCORE
    )

    if is_low_detail:
        return ImageQualityResult(
            accepted=False,
            code="image_too_blurry_or_low_contrast",
            detail=(
                "Image is too blurry or has too little contrast to identify "
                "a document reliably. Please retake the photo in better focus "
                "and lighting."
            ),
            blur_score=blur_score,
            contrast_score=contrast_score,
            bright_ratio=bright_ratio,
        )

    is_severely_overexposed = (
        bright_ratio >= SEVERE_BRIGHT_RATIO
        and contrast_score < MIN_CONTRAST_SCORE * 1.5
    )

    if is_severely_overexposed:
        return ImageQualityResult(
            accepted=False,
            code="image_severely_overexposed",
            detail=(
                "Image is severely overexposed and document details are not "
                "visible. Please retake the photo without direct glare."
            ),
            blur_score=blur_score,
            contrast_score=contrast_score,
            bright_ratio=bright_ratio,
        )

    document_frame_score = find_document_frame_score(analysis_image)

    if document_frame_score < MIN_DOCUMENT_FRAME_SCORE:
        return ImageQualityResult(
            accepted=False,
            code="document_frame_not_detected",
            detail=(
                "No document frame could be detected. Please place the full "
                "document inside the photo, keep its edges visible, and retake "
                "the image on a contrasting background."
            ),
            blur_score=blur_score,
            contrast_score=contrast_score,
            bright_ratio=bright_ratio,
            document_frame_score=document_frame_score,
        )

    return ImageQualityResult(
        accepted=True,
        code=None,
        detail=None,
        blur_score=blur_score,
        contrast_score=contrast_score,
        bright_ratio=bright_ratio,
        document_frame_score=document_frame_score,
    )


def find_document_frame_score(image: np.ndarray) -> float:
    """Chấm mức độ hiện diện của một khung giấy tờ bằng hình học nhẹ.

    Đây không phải detector thay thế cho ``test_document_crop.py``. Quality gate
    chỉ dùng nó để từ chối sớm khi hoàn toàn không còn ứng viên hình chữ nhật
    đáng tin cậy. Nhờ vậy các ảnh có giấy tờ bị nghiêng, có viền bị che một
    phần hoặc nền phức tạp vẫn được gửi đến detector đầy đủ của pipeline.
    """

    gray_image = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blurred_image = cv2.GaussianBlur(gray_image, (5, 5), 0)
    edge_image = cv2.Canny(blurred_image, 40, 120)
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (7, 7))
    connected_edges = cv2.morphologyEx(
        edge_image,
        cv2.MORPH_CLOSE,
        kernel,
        iterations=2,
    )
    contours, _ = cv2.findContours(
        connected_edges,
        cv2.RETR_LIST,
        cv2.CHAIN_APPROX_SIMPLE,
    )

    image_height, image_width = gray_image.shape
    image_area = float(image_height * image_width)
    best_score = 0.0

    for contour in contours:
        perimeter = cv2.arcLength(contour, True)

        if perimeter <= 0:
            continue

        contour_area = float(cv2.contourArea(contour))
        area_ratio = contour_area / image_area

        if not MIN_DOCUMENT_FRAME_AREA_RATIO <= area_ratio <= MAX_DOCUMENT_FRAME_AREA_RATIO:
            continue

        rectangle = cv2.minAreaRect(contour)
        rectangle_width, rectangle_height = rectangle[1]

        if rectangle_width <= 1 or rectangle_height <= 1:
            continue

        long_edge = max(rectangle_width, rectangle_height)
        short_edge = min(rectangle_width, rectangle_height)
        aspect_ratio = long_edge / short_edge

        # Thẻ, GPLX và giấy đăng ký xe thường nằm trong khoảng này kể cả
        # khi có biến dạng phối cảnh vừa phải. Không ép đúng tỷ lệ CCCD.
        if not 1.10 <= aspect_ratio <= 5.50:
            continue

        rectangle_area = rectangle_width * rectangle_height
        contour_fill_score = min(1.0, contour_area / rectangle_area)
        rectangle_points = cv2.boxPoints(rectangle)
        border_penalty = document_border_penalty(
            rectangle_points,
            image_width,
            image_height,
        )

        # Ảnh chụp thực tế thường làm Canny chia viền thẻ thành nhiều đoạn.
        # minAreaRect là tín hiệu dự phòng rẻ và khoan dung cho quality gate;
        # detector đầy đủ ở pipeline vẫn là nơi quyết định bốn góc cuối cùng.
        permissive_score = area_ratio * contour_fill_score * border_penalty
        best_score = max(best_score, float(permissive_score))

        approximation = cv2.approxPolyDP(contour, 0.02 * perimeter, True)

        if len(approximation) != 4 or not cv2.isContourConvex(approximation):
            continue

        rectangularity = min(1.0, contour_area / rectangle_area)
        angle_score = quadrilateral_angle_score(approximation.reshape(4, 2))
        ratio_score = min(1.0, (aspect_ratio - 1.0) / 0.60)
        area_score = min(1.0, area_ratio / 0.12)

        score = (
            rectangularity
            * angle_score
            * ratio_score
            * area_score
            * border_penalty
        )
        best_score = max(best_score, float(score))

    return best_score


def quadrilateral_angle_score(points: np.ndarray) -> float:
    """Trả về mức vuông góc trung bình, có dung sai cho phối cảnh."""

    angle_errors: list[float] = []

    for index in range(4):
        current = points[index].astype(np.float32)
        previous = points[(index - 1) % 4].astype(np.float32)
        following = points[(index + 1) % 4].astype(np.float32)
        first_vector = previous - current
        second_vector = following - current
        denominator = float(np.linalg.norm(first_vector) * np.linalg.norm(second_vector))

        if denominator == 0:
            return 0.0

        cosine = float(np.clip(np.dot(first_vector, second_vector) / denominator, -1.0, 1.0))
        angle = float(np.degrees(np.arccos(cosine)))
        angle_errors.append(abs(angle - 90.0))

    average_error = float(np.mean(angle_errors))

    if average_error > 38.0:
        return 0.0

    return 1.0 / (1.0 + average_error / 14.0)


def document_border_penalty(points: np.ndarray, image_width: int, image_height: int) -> float:
    """Không để một khung bao toàn bộ ảnh được xem là giấy tờ."""

    margin_x = image_width * 0.01
    margin_y = image_height * 0.01
    touches_image_border = sum(
        point[0] <= margin_x
        or point[0] >= image_width - margin_x
        or point[1] <= margin_y
        or point[1] >= image_height - margin_y
        for point in points
    )

    return 0.55 if touches_image_border >= 3 else 1.0


def resize_for_analysis(image: np.ndarray) -> np.ndarray:
    """Giảm kích thước khi cần để phép đo luôn nhanh và ổn định."""

    height, width = image.shape[:2]
    largest_edge = max(height, width)

    if largest_edge <= MAX_ANALYSIS_EDGE:
        return image

    scale = MAX_ANALYSIS_EDGE / largest_edge
    return cv2.resize(
        image,
        (round(width * scale), round(height * scale)),
        interpolation=cv2.INTER_AREA,
    )
