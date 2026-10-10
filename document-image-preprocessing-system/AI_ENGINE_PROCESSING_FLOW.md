# Luồng xử lý AI Engine

Tài liệu này mô tả luồng đang chạy trong project hiện tại. Laravel gọi FastAPI tại `POST /process`; AI Engine chạy pipeline tuần tự, đọc OCR trên ảnh đã xử lý, chuẩn hóa trường dữ liệu và trả kết quả về Laravel.

## 1. Sơ đồ tổng quát

```text
Người dùng chọn ảnh trên Laravel UI
        |
        v
DocumentController lưu ảnh gốc private
        |
        v
AiImageProcessor -> POST http://127.0.0.1:8000/process
        |
        v
orientation -> document crop/perspective -> deskew
        -> deglare -> deblur -> enhance
        -> document_final.jpg
        |
        +-> PP-OCRv5 detector -> VietOCR recognition
        +-> structured extraction theo nhãn + polygon
        +-> YuNet face detection/crop
        |
        v
Laravel lưu ảnh, OCR JSON và trạng thái vào private storage/MySQL
```

Pipeline không dùng UVDoc. `orientation_corrected.jpg` chỉ là output trung gian để kiểm tra chiều; nếu model dự đoán ảnh đã đúng chiều thì file có thể giống ảnh đầu vào. Ảnh Laravel hiển thị sau cùng là `document_final.jpg`.

## 2. API FastAPI

| Endpoint | Công dụng |
| --- | --- |
| `GET /health` | Trả `status: ok` để kiểm tra server |
| `POST /process` | Pipeline đầy đủ được Laravel sử dụng |
| `POST /ocr` | OCR trực tiếp ảnh upload, không chạy tiền xử lý |
| `POST /preprocess` | Chỉ tiền xử lý và trả ảnh JPEG |

AI Engine nhận `.jpg`, `.jpeg`, `.png`, `.webp`. File upload được đặt tạm trong `ai-engine/tmp/` rồi xóa. Vì pipeline sử dụng các output chung trong `ai-engine/output/`, `asyncio.Lock` chỉ cho một request chạy tại một thời điểm.

## 3. Các bước tiền xử lý

| Thứ tự | Script | Kết quả |
| --- | --- | --- |
| 1 | `test_orientation_rotate.py` | `orientation_corrected.jpg` |
| 2 | `test_document_crop.py` | `document_detection.jpg`, `document_mask.jpg`, `document_crop.jpg` |
| 3 | `test_deskew.py` | `document_deskew.jpg` |
| 4 | `test_glare.py` | `glare_mask.jpg`, `glare_detection.jpg`, `document_deglare.jpg` |
| 5 | `test_deblur.py` | `document_deblur.jpg` |
| 6 | `test_enhance.py` | `document_final.jpg` |

### 3.1 Chỉnh chiều

`PP-LCNet_x1_0_doc_ori` phân loại hướng 0/90/180/270 độ trên CPU. Bước này chỉ xoay toàn ảnh khi ảnh bị quay ngang hoặc lộn ngược; không xác định góc giấy tờ và không làm phẳng phối cảnh.

### 3.2 Xác định góc và perspective crop

`test_document_crop.py` dùng OpenCV với nhiều nhánh fallback hình học, không dùng YOLO và không dùng model detect tài liệu chuyên biệt. Các nhánh kết hợp Canny/morphology, contour tứ giác, mask HSV/LAB theo màu giấy tờ, `minAreaRect`, đường Hough và bố cục vùng chữ. Ứng viên được chấm theo diện tích, tỷ lệ thẻ, độ chữ nhật, góc và mức hợp lý so với ảnh.

Nếu tìm được bốn điểm, điểm được sắp theo thứ tự trên-trái, trên-phải, dưới-phải, dưới-trái rồi dùng `cv2.getPerspectiveTransform` + `cv2.warpPerspective` để crop và làm phẳng. Nếu không có khung đủ tin cậy, hệ thống giữ toàn ảnh thay vì cắt mất nội dung.

### 3.3 Deskew, khử chói, khử mờ và enhance

- Deskew dùng Canny + HoughLinesP để ước lượng góc đường văn bản, sau đó xoay với canvas mở rộng.
- Khử chói tìm vùng sáng/ít bão hòa trong HSV, rồi giảm sáng kênh L của LAB.
- Khử mờ đo độ sắc nét bằng Laplacian và áp dụng unsharp mask thích ứng.
- Enhance chỉ thu nhỏ ảnh quá lớn và dùng CLAHE trên kênh sáng LAB. Không phóng to ảnh nhỏ.

## 4. OCR trên ảnh cuối

Pipeline luôn OCR `output/document_final.jpg`, không OCR trực tiếp ảnh gốc.

### 4.1 Detect: PP-OCRv5

`ocr_service.py` dùng `PP-OCRv5_server_det` thông qua PaddleX/ONNX Runtime CPU. Detector trả polygon bốn điểm và confidence của vùng chữ. Mỗi polygon được nới nhẹ rồi perspective warp thành dòng thẳng để recognition, giúp hạn chế mất dấu tiếng Việt.

### 4.2 Recognition: VietOCR

Recognizer cố định là `VietOCR vgg_transformer`, chạy PyTorch CPU với cấu hình tiếng Việt. Model được khởi tạo một lần và khóa khi inference.

`ocr_raw_result.json` lưu toàn bộ dòng đọc được, text, confidence recognition, `detection_confidence` và polygon. Confidence chỉ là mức tin cậy tham khảo, không phải bảo đảm dữ liệu chính xác.

## 5. Chuẩn hóa dữ liệu và khuôn mặt

`test_structured_extraction.py` gọi `build_structured_result()` trong `structured_extraction.py`; bước này dùng OCR thô + polygon, không OCR lại.

1. Chuẩn hóa Unicode NFC, khoảng trắng và tạo khóa không dấu để nhận diện nhãn tiếng Việt bị mất dấu.
2. Nhận diện loại giấy tờ dựa trên tiêu đề và tổ hợp nhãn: CCCD, giấy phép lái xe hoặc giấy đăng ký xe.
3. Ghép nhãn với giá trị cùng dòng/bên phải; địa chỉ nhiều dòng được ghép theo anchor polygon và khoảng cách thích nghi theo chiều cao dòng.
4. Chuẩn hóa bảo thủ: không đủ căn cứ thì để trống; giữ dòng không gán trường trong `unmapped_raw_lines`.
5. Nhận diện khuôn mặt bằng `cv2.FaceDetectorYN` (YuNet) trên `document_final.jpg`, thêm padding và trả ảnh crop nếu phát hiện được.

Các bộ trường cố định:

- CCCD: số, họ tên, giới tính, ngày sinh, quốc tịch, quê quán, nơi thường trú, giá trị đến.
- Giấy phép lái xe: số, họ tên, ngày sinh, quốc tịch, nơi cư trú, hạng/class, giá trị đến.
- Đăng ký xe: tên chủ xe, địa chỉ, nhãn hiệu, số loại, số máy, số khung, màu sơn, hoạt động trong phạm vi, biển số, số chỗ ngồi, giá trị đến ngày, công suất, loại xe, dung tích.

## 6. Payload trả về cho Laravel

```json
{
  "status": "completed",
  "processed_image": {"filename": "document_final.jpg", "media_type": "image/jpeg", "base64": "..."},
  "ocr": {"raw": {}, "structured": {}},
  "face_crop": {"media_type": "image/jpeg", "base64": "..."}
}
```

`face_crop` là `null` nếu không phát hiện mặt. Laravel giải mã payload, lưu ảnh vào `storage/app/private/documents/processed/` và `faces/`, lưu hai JSON vào `image_documents`, rồi hiển thị ảnh cuối, các trường chuẩn hóa và toàn bộ dòng OCR theo quyền. Hai khối JSON kỹ thuật chỉ hiển thị cho Admin.

Nếu pipeline, HTTP payload, base64 hoặc JSON lỗi, Laravel đánh dấu hồ sơ `failed` và ghi chi tiết kỹ thuật vào `storage/logs/laravel.log`; UI chỉ hiển thị thông báo lỗi tổng quát.

## 7. Chạy thủ công

```bash
cd /home/user/document-image-preprocessing-system/ai-engine
source venv/bin/activate
python pipeline.py input/cccd_1.jpg
```

Output debug dùng chung và có thể bị ghi đè ở lượt chạy sau. Lịch sử thật của từng hồ sơ nằm trong private storage và MySQL do Laravel quản lý.
