# OCR model, provider và phạm vi đánh giá

Đối chiếu với `ai-engine/ocr_service.py`, `ai-engine/app.py`, `SystemSetting` và `structured_extraction.py` ngày **21/10/2026**. Tài liệu mô tả những nhánh đã được triển khai. Project chưa có báo cáo benchmark chuẩn hóa đủ để kết luận một model/provider chính xác hoặc nhanh nhất.

## 1. Luồng mặc định: local_hybrid

```text
Ảnh cuối document_final.jpg
  → PP-OCRv5_server_det: polygon + detection_confidence
  → expand_polygon / crop_text_line: nắn từng dòng theo phối cảnh
  → VietOCR vgg_transformer: text tiếng Việt + recognition confidence
  → raw OCR chung
  → làm sạch nhãn + chuẩn hóa fields + MRZ + barcode + YuNet
```

`/process` và `/process-batch` OCR ảnh đã tiền xử lý; `/ocr` OCR trực tiếp ảnh upload sau validate/quality gate. Các bước barcode và face crop dùng ảnh độc lập với recognizer chữ. Confidence OCR không phải xác suất được hiệu chuẩn hoặc bằng chứng giấy tờ thật.

| Thành phần | Triển khai trong code | Đầu ra / giới hạn |
| --- | --- | --- |
| Text detector | PaddleX `create_model("PP-OCRv5_server_det", device="cpu", engine="onnxruntime")` | Polygon và điểm detection; không tự nhận dạng nội dung |
| Text recognizer | VietOCR `Cfg.load_config_from_name("vgg_transformer")`, PyTorch CPU | Chuỗi chữ và confidence của từng dòng; phụ thuộc line crop |
| Orientation | `PP-LCNet_x1_0_doc_ori` trong bước tiền xử lý | Nhãn xoay 0/90/180/270; không phải text recognizer |
| Face detector | OpenCV YuNet trong `structured_extraction.py` | Crop khuôn mặt nếu có model và phát hiện được; không nhận dạng danh tính/liveness |
| Barcode | `zxing-cpp` trong `barcode_service.py` | Raw bytes/text và profile nhận diện được; không giải mọi payload thành thông tin cá nhân |
| MRZ | `mrz_parser.py` + bằng chứng ảnh `mrz_image.py` | Chuẩn hóa TD1 và định danh theo vị trí; chưa xác thực checksum ICAO |

Detector OCR còn được dùng trong fallback bố cục chữ của bước crop giấy tờ. Nhánh đó chỉ dùng polygon để suy ra khung; sau tiền xử lý vẫn OCR ảnh cuối. Nó chạy trong process của script crop, tách biệt với worker OCR chính.

## 2. Cache và worker

`ocr_service.py` đặt cache PaddleX tại `ai-engine/model-cache/`. VietOCR lưu config/weights ở `model-cache/vietocr/vgg_transformer.yml` và `vgg_transformer.pth`; khi thiếu, service bootstrap pretrained rồi lưu cache. Mỗi process giữ `_detector`, `_recognizer` và các lock khởi tạo/inference riêng.

FastAPI dùng `ProcessPoolExecutor` cho local OCR; mỗi worker tái sử dụng các model đã nạp qua những yêu cầu tiếp theo. Mặc định một FastAPI process có 2 slot job và 2 worker OCR; cấu hình `AI_MAX_CONCURRENT_JOBS` (1–4), `AI_LOCAL_OCR_WORKERS` (1 đến số job) điều chỉnh giới hạn này. Worker có thể được khởi tạo/nạp model khi nhận việc đầu tiên. Tăng worker có thể tăng lượng RAM và số instance model; không có số đo trong tài liệu bảo đảm tăng tuyến tính tốc độ.

Các adapter HTTP chạy qua `asyncio.to_thread`, không qua pool local. Khi chạy `pipeline.py` thủ công, OCR/chuẩn hóa được gọi bằng script con riêng; CLI không dùng pool thường trực của FastAPI. Sáu bước tiền xử lý cũng có process riêng, nên không thể nói mọi model trong toàn pipeline đều chỉ nạp một lần.

## 3. Bốn provider đã được triển khai

| Provider | Cách gọi thực tế | Cấu hình trong môi trường Uvicorn |
| --- | --- | --- |
| `local_hybrid` | PP-OCRv5 + VietOCR trên CPU | Cache/model và dependency local |
| `fpt_ai_reader` | POST multipart `image`, header `api_key`; chuyển response trường dữ liệu FPT về schema nội bộ | `FPT_AI_READER_API_KEY`, `FPT_AI_READER_DOCUMENT_TYPE`, URL tùy chọn, timeout |
| `vnpt_ekyc` | Kế thừa adapter HTTP generic: multipart `file`, Bearer token tùy chọn | `VNPT_EKYC_OCR_URL`, `VNPT_EKYC_OCR_API_KEY`, timeout; endpoint/gateway phải trả schema nội bộ |
| `external_ekyc` | POST multipart `file`, Bearer token tùy chọn | `EKYC_OCR_URL`, `EKYC_OCR_API_KEY`, timeout; endpoint phải trả schema nội bộ |

Nhánh `vnpt_ekyc` hiện chưa triển khai đầy đủ một workflow native VNPT gồm upload/hash/liveness/đối chiếu khuôn mặt. Tên provider này không có nghĩa hệ thống đã tích hợp mọi API eKYC của VNPT.

### FPT.AI Reader

`FPT_AI_READER_DOCUMENT_TYPE` hỗ trợ `id_card` và `driver_license`. Giá trị này cấu hình cho adapter, không được tự phân loại cho từng ảnh. URL mặc định trong source:

- `id_card`: `https://api.fpt.ai/vision/idr/vnm/`.
- `driver_license`: `https://api.fpt.ai/vision/dlr/vnm`.

`FPT_AI_READER_URL` có thể ghi đè. Adapter yêu cầu `errorCode == 0`, lấy object đầu tiên trong `data`, bỏ giá trị trống/N/A/null, chuyển các field được liệt kê trong `field_labels()` thành dòng `nhãn: giá trị`. Điểm field lấy từ `<key>_prob` nếu chuyển được sang float.

**Polygon của nhánh FPT là polygon tổng hợp để tương thích schema**, không phải tọa độ detection trên ảnh gốc. `detection_confidence` là null. Không dùng các tọa độ này để đánh giá chất lượng định vị hay coi chúng là bằng chứng bố cục thật. Nhánh này không trả mọi field vendor có thể cung cấp: source hiện chỉ ánh xạ nhóm số, họ tên, ngày sinh, quốc tịch, địa chỉ, giới tính/quê quán hoặc hạng bằng và hạn sử dụng tùy loại.

### VNPT/generic

Gateway phải trả object có các khóa `status`, `engine`, `line_count`, `full_text`, `lines`; `lines` phải là list. `validate_ocr_result()` hiện chỉ kiểm tra các khóa bắt buộc và kiểu list, không kiểm tra sâu toàn bộ polygon/field. Nhánh VNPT bổ sung `engine.provider = "VNPT eKYC"` nếu engine là dict và chưa có provider.

## 4. Chọn provider cho từng request

1. Admin lưu lựa chọn tại `/admin/ocr-vendor`; Laravel cập nhật `system_settings`, audit và file state dùng chung.
2. Request Laravel lấy lựa chọn từ database hợp lệ hoặc config mặc định, rồi gửi `X-OCR-Provider` cho AI Engine.
3. Nếu gọi FastAPI không có header, engine đọc `AI_OCR_PROVIDER_STATE_PATH` (mặc định `storage/app/private/ocr-provider.json`), rồi fallback `OCR_PROVIDER` nếu file không có lựa chọn hợp lệ.
4. Một request batch chốt cùng provider cho các ảnh trong request đó. Không gộp output của nhiều vendor và không tự fallback khi vendor lỗi.

Thay lựa chọn trong Admin áp dụng cho yêu cầu mới mà không restart. Credentials, endpoint và timeout không được nhập ở giao diện Admin; chúng thuộc môi trường Uvicorn và thay đổi môi trường cần restart process. `GET /metrics` hoặc `/metric` phản ánh lựa chọn state/environment ở lần gọi hiện tại, không chứng minh credential hợp lệ hoặc cho biết provider override của một request đã qua.

## 5. Schema OCR và chuẩn hóa

Raw OCR có `status`, `engine`, `line_count`, `full_text`, `lines`. Nhánh local trả mỗi dòng gồm `index`, `text`, `confidence`, `detection_confidence`, `polygon`. Structured OCR bổ sung loại giấy tờ/phiên bản CCCD, `fields`, MRZ, barcode, dấu vết làm sạch, thông tin verification, face crop, toàn bộ dòng và dòng chưa gán.

Các field giữ `raw_value`, `normalized_value`, confidence và nguồn dòng khi có. Schema CCCD còn materialize trường không đọc được với `extraction_status=not_detected`. Dữ liệu ghép hai mặt có thêm nguồn `front/back`; barcode bổ sung trường trống với `source_type=barcode` sau khi đối chiếu hợp lệ. Customer có thể sửa field được phép trước khi gửi duyệt; thao tác đó có dấu `manually_edited`, không thay kết quả raw OCR.

Face crop chỉ có khi tìm thấy model tại đường dẫn `model-cache/face-detection/face_detection_yunet_2026may.onnx` và phát hiện được mặt. Nếu thiếu model, trả `model_not_available`; nếu không thấy mặt, trả `not_detected`. Đây không phải lỗi OCR và response ảnh mặt là null.

## 6. Phạm vi đánh giá hiện có

Các test trong repo kiểm tra cache/model lifecycle, lựa chọn provider, hợp đồng adapter, làm sạch văn bản, trích xuất theo bố cục, MRZ/barcode và điều kiện ghép mặt. Một số file tên `test_*.py` là **script pipeline chạy thật**, không phải test unittest; không dùng lệnh discover toàn bộ mà chưa phân biệt hai nhóm.

Project có công cụ xuất kết quả theo ảnh/batch (`export_structured_ocr_batch.py`, `export_cccd_pair_ocr.py`, `export_cccd_pair_batch.py`). Kết quả phụ thuộc ảnh đầu vào và model/cache/config của lần chạy. Những công cụ này không tự cung cấp bảng ground truth, CER/WER hoặc bằng chứng một model tốt hơn mọi phương án khác.

Chưa có bảng benchmark đã kiểm chứng chung cho mọi provider. Chưa thể so sánh confidence local với confidence vendor như cùng một thang độ chính xác. Payload PDF417 nhị phân của ảnh CCCD 2016 đã đọc được ở lớp barcode nhưng parser định danh chưa hỗ trợ; không tính nó là một trường hợp trích xuất thông tin cá nhân thành công.

## 7. Các phương án khảo sát chưa tích hợp

EasyOCR, Tesseract, docTR, TrOCR và việc thay VietOCR bằng recognizer PP-OCRv5 chưa có nhánh provider trong code hiện tại. Khảo sát các phương án này là hướng nghiên cứu, không phải chức năng đã triển khai hay kết luận benchmark. Nếu đánh giá sau này, cần dùng cùng tập ảnh/ground truth và ghi riêng độ chính xác ký tự, trường định danh, bố cục và thời gian xử lý.

Các liên kết tham khảo đã có trong tài liệu khảo sát:

- [PaddleOCR](https://github.com/PaddlePaddle/PaddleOCR)
- [VietOCR](https://github.com/pbcquoc/vietocr)
- [EasyOCR](https://github.com/JaidedAI/EasyOCR)
- [Tesseract](https://github.com/tesseract-ocr/tesseract)
- [docTR](https://github.com/mindee/doctr)
- [TrOCR](https://huggingface.co/docs/transformers/model_doc/trocr)

Các nguồn trên dùng để tìm tài liệu của từng công cụ; phần mô tả runtime của hệ thống trong tài liệu này được đối chiếu từ source local, không xác nhận lại khả năng hay phiên bản mới nhất của các công cụ khảo sát.

## 8. Dependency và thay đổi chuẩn hóa hiện tại

Cấu hình cài mới CPU nằm trong `ai-engine/requirements.txt`, được `requirement.txt` ở root tham chiếu. Detector và orientation đều chỉ định ONNX Runtime; không cài PaddlePaddle/CUDA chỉ để chạy hai model này. VietOCR dùng torch/torchvision CPU từ index chính thức. NumPy 1.26.4 và các gói OpenCV 4.10.0.84 được ghim cho môi trường cài mới để tránh trộn cv2 4.x/5.x và dependency cũ imgaug. Các tên opencv-python, opencv-python-headless và opencv-contrib-python cùng tồn tại vì metadata phụ thuộc của VietOCR/PaddleX; chúng cùng phiên bản. Không thay các file trong venv Linux đang có.

Nguồn cài CPU: [PyTorch CPU wheels](https://download.pytorch.org/whl/cpu/torch/), [Torchvision CPU wheels](https://download.pytorch.org/whl/cpu/torchvision/), [VietOCR trên PyPI](https://pypi.org/project/vietocr/). Danh sách requirements.txt ghim gói trực tiếp; Linux có thêm requirements-linux.lock.txt với 110 package runtime từ môi trường đã kiểm thử. Khi đổi dependency cần cập nhật lock và kiểm thử lại; Windows vẫn dùng requirements.txt. Setuptools được ghim 80.10.2 vì gdown 4.4.0 còn import pkg_resources, không dùng setuptools >=81.

Bootstrap VietOCR dùng tempfile.gettempdir() để tìm trọng số vừa tải, thay đường dẫn /tmp cố định, tương thích thư mục tạm Windows/Linux. Model OCR có thể tải ở lần xử lý đầu; /health không bảo đảm model đã tải. YuNet vẫn cần file model đúng đường dẫn trong source, không được bộ chạy tự tải.

Parser QR chấp nhận phần phân cách rỗng ở cuối payload bảy trường; phần bổ sung khác rỗng vẫn unsupported. Sửa OCR bằng tay hiện cho phép bổ sung các field hiển thị của bằng lái và đăng ký xe, ngoài CCCD; giá trị do người dùng sửa không phải kết quả đo độ chính xác của model.
