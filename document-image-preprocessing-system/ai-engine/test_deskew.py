import cv2
import numpy as np


INPUT_IMAGE = "output/document_crop.jpg"
OUTPUT_IMAGE = "output/document_deskew.jpg"


def normalize_angle(angle):
    """
    Đưa angle về khoảng [-90, 90].
    """
    while angle <= -90:
        angle += 180

    while angle > 90:
        angle -= 180

    return angle


def detect_skew_angle(image):
    """
    Phát hiện góc nghiêng nhỏ bằng Hough Line Transform.
    Chỉ lấy các đường gần ngang để ước lượng deskew.
    """

    gray = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2GRAY
    )

    # Giảm nhiễu
    blurred = cv2.GaussianBlur(
        gray,
        (5, 5),
        0
    )

    # Tìm cạnh
    edges = cv2.Canny(
        blurred,
        50,
        150
    )

    # Tìm các đường thẳng
    lines = cv2.HoughLinesP(
        edges,
        rho=1,
        theta=np.pi / 180,
        threshold=80,
        minLineLength=int(
            min(image.shape[:2]) * 0.25
        ),
        maxLineGap=20
    )

    if lines is None:
        return 0.0

    angles = []

    lines=lines.reshape(-1, 4)
    for x1,y1,x2,y2 in lines:

        dx = x2 - x1
        dy = y2 - y1

        if dx == 0:
            continue

        angle = np.degrees(
            np.arctan2(dy, dx)
        )

        angle = normalize_angle(angle)

        # Chỉ lấy các đường gần ngang
        if abs(angle) <= 15:
            angles.append(angle)

    if not angles:
        return 0.0

    # Median ổn định hơn lấy average
    return float(
        np.median(angles)
    )


def rotate_image(image, angle):
    """
    Xoay ảnh quanh tâm và giữ toàn bộ ảnh.
    """

    height, width = image.shape[:2]

    center = (
        width // 2,
        height // 2
    )

    matrix = cv2.getRotationMatrix2D(
        center,
        angle,
        1.0
    )

    cos = abs(matrix[0, 0])
    sin = abs(matrix[0, 1])

    new_width = int(
        (height * sin) +
        (width * cos)
    )

    new_height = int(
        (height * cos) +
        (width * sin)
    )

    matrix[0, 2] += (
        new_width / 2
        - center[0]
    )

    matrix[1, 2] += (
        new_height / 2
        - center[1]
    )

    return cv2.warpAffine(
        image,
        matrix,
        (new_width, new_height),
        flags=cv2.INTER_CUBIC,
        borderMode=cv2.BORDER_REPLICATE
    )


# ==========================================================
# 1. READ IMAGE
# ==========================================================

image = cv2.imread(
    INPUT_IMAGE
)

if image is None:
    raise FileNotFoundError(
        f"Cannot open image: {INPUT_IMAGE}"
    )


# ==========================================================
# 2. DETECT SKEW
# ==========================================================

skew_angle = detect_skew_angle(
    image
)

print(
    f"Detected skew angle: "
    f"{skew_angle:.2f}°"
)


# ==========================================================
# 3. IGNORE VERY SMALL ANGLE
# ==========================================================

if abs(skew_angle) < 0.3:

    print(
        "Ảnh gần như đã thẳng."
    )

    result = image

else:

    # Xoay ngược góc nghiêng
    correction_angle = -skew_angle

    print(
        f"Correction angle: "
        f"{correction_angle:.2f}°"
    )

    result = rotate_image(
        image,
        correction_angle
    )


# ==========================================================
# 4. SAVE
# ==========================================================

success = cv2.imwrite(
    OUTPUT_IMAGE,
    result
)

if not success:
    raise RuntimeError(
        "Cannot save deskew image."
    )


print()
print(
    "=============================="
)

print(
    "Deskew completed!"
)

print(
    f"Output: {OUTPUT_IMAGE}"
)

print(
    "=============================="
)
