import cv2
import os


INPUT_IMAGE = "output/document_deblur.jpg"

OUTPUT_DIR = "output"
OUTPUT_IMAGE = f"{OUTPUT_DIR}/document_final.jpg"

# Kích thước chuẩn
TARGET_WIDTH = 1600


# =========================================================
# Resize giữ nguyên tỉ lệ
# =========================================================

def resize_image(image, target_width=1600):

    height, width = image.shape[:2]

    # Không phóng to ảnh nhỏ
    if width <= target_width:
        return image

    scale = target_width / width

    new_width = int(width * scale)
    new_height = int(height * scale)

    return cv2.resize(
        image,
        (new_width, new_height),
        interpolation=cv2.INTER_AREA
    )


# =========================================================
# Enhance contrast bằng CLAHE
# =========================================================

def enhance_image(image):

    # Chuyển sang LAB
    lab = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2LAB
    )

    l_channel, a_channel, b_channel = cv2.split(lab)

    # CLAHE tăng tương phản cục bộ
    clahe = cv2.createCLAHE(
        clipLimit=2.0,
        tileGridSize=(8, 8)
    )

    enhanced_l = clahe.apply(
        l_channel
    )

    enhanced_lab = cv2.merge(
        [
            enhanced_l,
            a_channel,
            b_channel
        ]
    )

    result = cv2.cvtColor(
        enhanced_lab,
        cv2.COLOR_LAB2BGR
    )

    return result


# =========================================================
# Main
# =========================================================

os.makedirs(
    OUTPUT_DIR,
    exist_ok=True
)


image = cv2.imread(
    INPUT_IMAGE
)

if image is None:
    raise FileNotFoundError(
        f"Không thể đọc ảnh: {INPUT_IMAGE}"
    )


print(
    f"Input size: "
    f"{image.shape[1]} x {image.shape[0]}"
)


# =========================================================
# Resize
# =========================================================

resized = resize_image(
    image,
    TARGET_WIDTH
)

print(
    f"Resized size: "
    f"{resized.shape[1]} x "
    f"{resized.shape[0]}"
)


# =========================================================
# Enhance
# =========================================================

print(
    "Enhancing image..."
)

result = enhance_image(
    resized
)


# =========================================================
# Save
# =========================================================

success = cv2.imwrite(
    OUTPUT_IMAGE,
    result,
    [
        cv2.IMWRITE_JPEG_QUALITY,
        95
    ]
)

if not success:
    raise RuntimeError(
        "Không thể lưu ảnh cuối."
    )


print()
print(
    "===== FINAL IMAGE ====="
)

print(
    f"Input : {INPUT_IMAGE}"
)

print(
    f"Output: {OUTPUT_IMAGE}"
)

print(
    "Resize + Enhance completed!"
)

