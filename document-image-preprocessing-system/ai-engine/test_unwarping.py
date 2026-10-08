import shutil
from pathlib import Path

from paddleocr import TextImageUnwarping

model = TextImageUnwarping(
    model_name="UVDoc",
    engine="onnxruntime",
    device="cpu",
)

# UVDoc xử lý sau khi giấy tờ đã được tách khỏi nền, tránh để mô hình làm mất
# biên cần thiết cho bước xác định bốn góc.
image_path = "output/document_crop.jpg"
output_path = Path("output/orientation_corrected_res.jpg")
model_output_path = Path("output/document_crop_res.jpg")

# Không để pipeline vô tình dùng output cũ nếu UVDoc không sinh được ảnh mới.
if output_path.exists():
    output_path.unlink()

if model_output_path.exists():
    model_output_path.unlink()

results = model.predict(
    image_path,
    batch_size=1,
)

for result in results:
    result.print(json_format=False)
    result.save_to_img(save_path="./output")
    result.save_to_json(save_path="./output")


if not model_output_path.exists():
    raise FileNotFoundError(
        f"UVDoc không tạo được ảnh kết quả: {model_output_path}"
    )


shutil.copy2(
    model_output_path,
    output_path
)
