import cv2
import numpy as np
import os


INPUT_IMAGE = "output/document_deskew.jpg"

OUTPUT_DIR = "output"

GLARE_MASK = f"{OUTPUT_DIR}/glare_mask.jpg"
GLARE_DETECTION = f"{OUTPUT_DIR}/glare_detection.jpg"
OUTPUT_IMAGE = f"{OUTPUT_DIR}/document_deglare.jpg"


# =========================================================
# Detect glare / overexposed regions
# =========================================================

def detect_glare(image):

    hsv = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2HSV
    )

    # Vùng rất sáng và ít bão hòa màu
    lower = np.array(
        [0, 0, 235],
        dtype=np.uint8
    )

    upper = np.array(
        [180, 60, 255],
        dtype=np.uint8
    )

    raw_mask = cv2.inRange(
        hsv,
        lower,
        upper
    )

    # Nối các vùng sáng gần nhau
    kernel = cv2.getStructuringElement(
        cv2.MORPH_ELLIPSE,
        (9, 9)
    )

    raw_mask = cv2.morphologyEx(
        raw_mask,
        cv2.MORPH_CLOSE,
        kernel,
        iterations=2
    )

    # =====================================================
    # Lọc connected components
    # =====================================================

    num_labels, labels, stats, centroids = (
        cv2.connectedComponentsWithStats(
            raw_mask,
            connectivity=8
        )
    )

    final_mask = np.zeros_like(raw_mask)

    image_height, image_width = image.shape[:2]

    image_area = (
        image_height *
        image_width
    )

    # Không lấy những vùng quá nhỏ
    min_area = image_area * 0.0005

    for i in range(1, num_labels):

        x = stats[i, cv2.CC_STAT_LEFT]
        y = stats[i, cv2.CC_STAT_TOP]

        w = stats[i, cv2.CC_STAT_WIDTH]
        h = stats[i, cv2.CC_STAT_HEIGHT]

        area = stats[i, cv2.CC_STAT_AREA]

        # 1. Bỏ vùng quá nhỏ
        if area < min_area:
            continue

        # 2. Bỏ vùng quá sát/chạm biên ảnh
        if (
            x <= 2 or
            y <= 2 or
            x + w >= image_width - 2 or
            y + h >= image_height - 2
        ):
            continue

        # 3. Bỏ các điểm quá nhỏ
        if w < 20 or h < 20:
            continue

        # 4. Độ đặc của vùng
        extent = area / (w * h)

        if extent < 0.15:
            continue

        final_mask[labels == i] = 255

    return final_mask


# =========================================================
# Reduce glare
# =========================================================

def reduce_glare(image, mask):

    # Chuyển sang LAB để điều chỉnh độ sáng riêng
    lab = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2LAB
    )

    l_channel, a_channel, b_channel = cv2.split(lab)

    # Giảm độ sáng mạnh ở vùng glare
    #
    # Những pixel có L quá cao
    # sẽ được kéo xuống một mức an toàn hơn.
    l_float = l_channel.astype(
        np.float32
    )

    glare_pixels = mask > 0

    l_float[glare_pixels] = (
        l_float[glare_pixels] * 0.82
    )

    l_float = np.clip(
        l_float,
        0,
        255
    )

    l_channel = l_float.astype(
        np.uint8
    )

    lab_result = cv2.merge(
        [
            l_channel,
            a_channel,
            b_channel
        ]
    )

    result = cv2.cvtColor(
        lab_result,
        cv2.COLOR_LAB2BGR
    )

    return result


# =========================================================
# Draw detected glare regions
# =========================================================

def draw_glare_regions(image, mask):

    result = image.copy()

    contours, _ = cv2.findContours(
        mask,
        cv2.RETR_EXTERNAL,
        cv2.CHAIN_APPROX_SIMPLE
    )

    for contour in contours:

        area = cv2.contourArea(
            contour
        )

        if area < 50:
            continue

        x, y, w, h = cv2.boundingRect(
            contour
        )

        cv2.rectangle(
            result,
            (x, y),
            (x + w, y + h),
            (0, 0, 255),
            3
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
    f"Input image size: "
    f"{image.shape[1]} x {image.shape[0]}"
)


# =========================================================
# 1. Detect glare
# =========================================================

print(
    "Detecting glare..."
)

mask = detect_glare(
    image
)


# =========================================================
# Count glare area
# =========================================================

glare_pixels = np.count_nonzero(
    mask
)

total_pixels = (
    image.shape[0]
    * image.shape[1]
)

glare_ratio = (
    glare_pixels
    / total_pixels
    * 100
)


print(
    f"Glare area: "
    f"{glare_ratio:.2f}%"
)


# =========================================================
# 2. Save mask
# =========================================================

cv2.imwrite(
    GLARE_MASK,
    mask
)


# =========================================================
# 3. Save detection preview
# =========================================================

detection_preview = draw_glare_regions(
    image,
    mask
)

cv2.imwrite(
    GLARE_DETECTION,
    detection_preview
)


# =========================================================
# 4. Reduce glare
# =========================================================

print(
    "Reducing glare..."
)

result = reduce_glare(
    image,
    mask
)


# =========================================================
# 5. Save result
# =========================================================

success = cv2.imwrite(
    OUTPUT_IMAGE,
    result
)

if not success:

    raise RuntimeError(
        "Không thể lưu ảnh khử lóe."
    )


print()
print(
    "===== GLARE PROCESSING ====="
)

print(
    f"Input: {INPUT_IMAGE}"
)

print(
    f"Glare mask: {GLARE_MASK}"
)

print(
    f"Detection: {GLARE_DETECTION}"
)

print(
    f"Output: {OUTPUT_IMAGE}"
)

print(
    "Glare reduction completed!"
)
