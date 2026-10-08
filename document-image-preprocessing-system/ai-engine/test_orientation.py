from paddleocr import DocImgOrientationClassification

model = DocImgOrientationClassification(
    model_name="PP-LCNet_x1_0_doc_ori",
    engine="onnxruntime",
    device="cpu",
)

image_path = "input/cccd_1.jpg"

results = model.predict(
    image_path,
    batch_size=1,
)

for result in results:
    result.print(json_format=False)
