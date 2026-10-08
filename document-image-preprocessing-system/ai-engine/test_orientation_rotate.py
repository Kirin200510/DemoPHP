import cv2
import os
from paddleocr import DocImgOrientationClassification


INPUT_IMAGE = os.getenv(
    "PIPELINE_INPUT",
    "input/cccd_1.jpg"
)
OUTPUT_IMAGE = "output/orientation_corrected.jpg"


def rotate_to_upright(image, label):
    """
    Xoay ảnh dựa trên kết quả orientation classification.

    label:
        0   -> ảnh đã đúng chiều
        90  -> xoay ngược 90°
        180 -> xoay 180°
        270 -> xoay ngược 270° (tương đương xoay 90°)
    """

    if label == "0":
        return image

    elif label == "90":
        # Ảnh đang lệch 90° -> xoay ngược chiều kim đồng hồ 90°
        return cv2.rotate(image, cv2.ROTATE_90_COUNTERCLOCKWISE)

    elif label == "180":
        # Ảnh đang ngược 180°
        return cv2.rotate(image, cv2.ROTATE_180)

    elif label == "270":
        # Ảnh đang lệch 270° -> xoay ngược 270°
        # tương đương xoay 90° theo chiều kim đồng hồ
        return cv2.rotate(image, cv2.ROTATE_90_CLOCKWISE)

    else:
        raise ValueError(f"Không nhận diện được orientation: {label}")


# =========================
# 1. Load model
# =========================

model = DocImgOrientationClassification(
    model_name="PP-LCNet_x1_0_doc_ori",
    engine="onnxruntime",
    device="cpu",
)


# =========================
# 2. Predict orientation
# =========================

results = model.predict(
    INPUT_IMAGE,
    batch_size=1,
)


# =========================
# 3. Load ảnh gốc
# =========================

image = cv2.imread(INPUT_IMAGE)

if image is None:
    raise FileNotFoundError(
        f"Không thể đọc ảnh: {INPUT_IMAGE}"
    )


# =========================
# 4. Lấy kết quả orientation
# =========================

for result in results:

    result.print(json_format=False)

    res = result.json["res"]

    class_id = int(res["class_ids"][0][0])
    score = float(res["scores"][0])
    label = str(res["label_names"][0])

    print()
    print("===== Orientation Result =====")
    print(f"Class ID   : {class_id}")
    print(f"Label      : {label}")
    print(f"Confidence : {score:.4f}")
    print(f"Confidence : {score * 100:.2f}%")
    print("==============================")
    print()


    # =========================
    # 5. Xoay ảnh
    # =========================

    corrected_image = rotate_to_upright(
        image,
        label
    )


    # =========================
    # 6. Save ảnh đã xoay
    # =========================

    success = cv2.imwrite(
        OUTPUT_IMAGE,
        corrected_image
    )

    if not success:
        raise RuntimeError(
            "Không thể lưu ảnh kết quả."
        )


    print(f"Ảnh đã được hiệu chỉnh: {OUTPUT_IMAGE}")
