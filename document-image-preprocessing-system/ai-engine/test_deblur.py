import cv2
import numpy as np
import os


INPUT_IMAGE = "output/document_deglare.jpg"

OUTPUT_DIR = "output"

OUTPUT_IMAGE = f"{OUTPUT_DIR}/document_deblur.jpg"


# =========================================================
# Blur score
# =========================================================

def calculate_blur_score(image):

    gray = cv2.cvtColor(
        image,
        cv2.COLOR_BGR2GRAY
    )

    score = cv2.Laplacian(
        gray,
        cv2.CV_64F
    ).var()

    return float(score)


# =========================================================
# Adaptive sharpening
# =========================================================

def unsharp_mask(
    image,
    amount=1.3,
    sigma=1.0
):
    """
    Làm ảnh sắc nét hơn bằng Unsharp Mask.

    amount:
        Mức độ sharpening.

    sigma:
        Độ rộng blur dùng để tạo lớp sharpening.
    """

    blurred = cv2.GaussianBlur(
        image,
        (0, 0),
        sigmaX=sigma
    )

    sharpened = cv2.addWeighted(
        image,
        1.0 + amount,
        blurred,
        -amount,
        0
    )

    return sharpened


# =========================================================
# Deblur
# =========================================================

def deblur_image(image, blur_score):

    # Ảnh khá rõ
    if blur_score >= 500:
        print(
            "Ảnh khá rõ -> không cần sharpen mạnh."
        )

        return image

    # Mờ nhẹ
    elif blur_score >= 250:

        print(
            "Phát hiện blur nhẹ."
        )

        return unsharp_mask(
            image,
            amount=0.8,
            sigma=1.0
        )

    # Mờ trung bình
    elif blur_score >= 100:

        print(
            "Phát hiện blur trung bình."
        )

        return unsharp_mask(
            image,
            amount=1.2,
            sigma=1.0
        )

    # Mờ nhiều
    else:

        print(
            "Phát hiện blur mạnh."
        )

        return unsharp_mask(
            image,
            amount=1.5,
            sigma=1.2
        )


# =========================================================
# Main
# =========================================================

os.makedirs(
    OUTPUT_DIR,
    exist_ok=True
)


# =========================================================
# Load image
# =========================================================

image = cv2.imread(
    INPUT_IMAGE
)

if image is None:

    raise FileNotFoundError(
        f"Không thể đọc ảnh: {INPUT_IMAGE}"
    )


print(
    f"Input image size: "
    f"{image.shape[1]} x "
    f"{image.shape[0]}"
)


# =========================================================
# Blur score BEFORE
# =========================================================

blur_before = calculate_blur_score(
    image
)


print()
print(
    "===== BLUR DETECTION ====="
)

print(
    f"Blur score BEFORE: "
    f"{blur_before:.2f}"
)


# =========================================================
# Deblur
# =========================================================

print()
print(
    "Processing deblur..."
)

result = deblur_image(
    image,
    blur_before
)


# =========================================================
# Blur score AFTER
# =========================================================

blur_after = calculate_blur_score(
    result
)


print(
    f"Blur score AFTER: "
    f"{blur_after:.2f}"
)


# =========================================================
# Save result
# =========================================================

success = cv2.imwrite(
    OUTPUT_IMAGE,
    result
)

if not success:

    raise RuntimeError(
        "Không thể lưu ảnh deblur."
    )


# =========================================================
# Result
# =========================================================

print()
print(
    "===== DEBLUR RESULT ====="
)

print(
    f"Input : {INPUT_IMAGE}"
)

print(
    f"Output: {OUTPUT_IMAGE}"
)

print(
    "Deblur completed!"
)
