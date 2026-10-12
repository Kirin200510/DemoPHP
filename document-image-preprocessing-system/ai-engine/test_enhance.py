import math
import os

import cv2


INPUT_IMAGE = "output/document_deblur.jpg"
OUTPUT_DIR = "output"
OUTPUT_IMAGE = f"{OUTPUT_DIR}/document_final.jpg"

MIN_OUTPUT_BYTES = 98 * 1024
MAX_OUTPUT_BYTES = 3 * 1024 * 1024
MIN_OUTPUT_DIMENSION = 350
MAX_OUTPUT_DIMENSION = 4096
TARGET_WIDTH = 1600
JPEG_QUALITY_START = 95
JPEG_QUALITY_MIN = 50


def resize_to_output_dimensions(image):
    """Đưa ảnh kết quả vào giới hạn pixel mà không làm méo tỷ lệ."""

    height, width = image.shape[:2]
    scale = min(
        1.0,
        TARGET_WIDTH / width if width > TARGET_WIDTH else 1.0,
        MAX_OUTPUT_DIMENSION / width,
        MAX_OUTPUT_DIMENSION / height,
    )

    if scale < 1.0:
        width = max(1, int(round(width * scale)))
        height = max(1, int(round(height * scale)))
        image = cv2.resize(image, (width, height), interpolation=cv2.INTER_AREA)

    height, width = image.shape[:2]
    scale = max(
        1.0,
        MIN_OUTPUT_DIMENSION / width,
        MIN_OUTPUT_DIMENSION / height,
    )

    if (
        scale > 1.0
        and width * scale <= MAX_OUTPUT_DIMENSION
        and height * scale <= MAX_OUTPUT_DIMENSION
    ):
        width = int(round(width * scale))
        height = int(round(height * scale))
        image = cv2.resize(image, (width, height), interpolation=cv2.INTER_CUBIC)

    height, width = image.shape[:2]
    padding_top = max(0, (MIN_OUTPUT_DIMENSION - height) // 2)
    padding_bottom = max(0, MIN_OUTPUT_DIMENSION - height - padding_top)
    padding_left = max(0, (MIN_OUTPUT_DIMENSION - width) // 2)
    padding_right = max(0, MIN_OUTPUT_DIMENSION - width - padding_left)

    if padding_top or padding_bottom or padding_left or padding_right:
        image = cv2.copyMakeBorder(
            image,
            padding_top,
            padding_bottom,
            padding_left,
            padding_right,
            cv2.BORDER_CONSTANT,
            value=(255, 255, 255),
        )

    return image


def encode_jpeg(image, quality):
    """Mã hóa JPEG và trả bytes để kiểm soát dung lượng file kết quả."""

    success, encoded = cv2.imencode(
        ".jpg",
        image,
        [cv2.IMWRITE_JPEG_QUALITY, quality],
    )

    if not success:
        raise RuntimeError("Không thể mã hóa ảnh JPEG cuối.")

    return encoded.tobytes()


def add_jpeg_comment_padding(image_bytes):
    """Bổ sung COM segment hợp lệ khi JPEG quá nhỏ so với chuẩn file tối thiểu."""

    required_padding = MIN_OUTPUT_BYTES - len(image_bytes)

    if required_padding <= 0:
        return image_bytes

    if not image_bytes.startswith(b"\xff\xd8"):
        raise RuntimeError("Ảnh đầu ra không phải JPEG hợp lệ.")

    comments = []

    while required_padding > 0:
        payload_size = min(65531, max(0, required_padding - 4))
        comments.append(
            b"\xff\xfe"
            + (payload_size + 2).to_bytes(2, "big")
            + (b" " * payload_size)
        )
        required_padding -= payload_size + 4

    return image_bytes[:2] + b"".join(comments) + image_bytes[2:]


def encode_with_standard_file_size(image):
    """Giữ JPEG trong 98 KB–3 MB bằng quality trước, sau đó giảm kích thước nếu cần."""

    current_image = image

    for _ in range(8):
        for quality in range(JPEG_QUALITY_START, JPEG_QUALITY_MIN - 1, -5):
            image_bytes = encode_jpeg(current_image, quality)

            if len(image_bytes) <= MAX_OUTPUT_BYTES:
                return add_jpeg_comment_padding(image_bytes)

        height, width = current_image.shape[:2]
        scale = min(0.9, math.sqrt(MAX_OUTPUT_BYTES / len(image_bytes)) * 0.95)
        next_width = max(MIN_OUTPUT_DIMENSION, int(width * scale))
        next_height = max(MIN_OUTPUT_DIMENSION, int(height * scale))

        if (next_width, next_height) == (width, height):
            break

        current_image = cv2.resize(
            current_image,
            (next_width, next_height),
            interpolation=cv2.INTER_AREA,
        )

    raise RuntimeError("Không thể nén ảnh kết quả xuống dưới 3 MB.")


def enhance_image(image):
    """Tăng tương phản cục bộ trên kênh sáng LAB."""

    lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB)
    l_channel, a_channel, b_channel = cv2.split(lab)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    enhanced_l = clahe.apply(l_channel)
    enhanced_lab = cv2.merge([enhanced_l, a_channel, b_channel])

    return cv2.cvtColor(enhanced_lab, cv2.COLOR_LAB2BGR)


def main():
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    image = cv2.imread(INPUT_IMAGE)

    if image is None:
        raise FileNotFoundError(f"Không thể đọc ảnh: {INPUT_IMAGE}")

    print(f"Input size: {image.shape[1]} x {image.shape[0]}")

    resized = resize_to_output_dimensions(image)
    print(f"Resized size: {resized.shape[1]} x {resized.shape[0]}")
    print("Enhancing image...")

    image_bytes = encode_with_standard_file_size(enhance_image(resized))
    with open(OUTPUT_IMAGE, "wb") as output_file:
        output_file.write(image_bytes)

    print()
    print("===== FINAL IMAGE =====")
    print(f"Input : {INPUT_IMAGE}")
    print(f"Output: {OUTPUT_IMAGE}")
    print(f"Output size: {len(image_bytes)} bytes")
    print("Resize + Enhance completed!")


if __name__ == "__main__":
    main()
