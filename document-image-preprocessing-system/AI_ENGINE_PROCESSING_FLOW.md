# Luồng xử lý AI Engine

Tài liệu này mô tả luồng **đang chạy trong project hiện tại**. Laravel/UI gọi `POST /process` trong `ai-engine/app.py`; pipeline đầy đủ nằm ở `ai-engine/pipeline.py`.

## Tổng quan

```text
Người dùng chọn ảnh trên Laravel UI
    │
    ▼
Laravel DocumentController
    ├─ Lưu ảnh gốc vào private storage
    └─ AiImageProcessor gọi FastAPI POST /process
        │
        ▼
AI Engine
    ├─ Lưu upload tạm theo UUID + khóa xử lý tuần tự
    ├─ Tiền xử lý ảnh
    │  orientation → crop/perspective → deskew → deglare → deblur → enhance
    ├─ OCR document_final.jpg
    │  PP-OCRv5_server_det → VietOCR vgg_transformer
    ├─ Chuẩn hóa dữ liệu OCR theo nhãn và bố cục polygon
    └─ YuNet detect/crop khuôn mặt trên ảnh cuối
        │
        ▼
FastAPI trả ảnh JPEG base64 + OCR thô + OCR chuẩn hóa + face crop nếu có
    │
    ▼
Laravel lưu ảnh, JSON OCR và metadata vào private storage/MySQL
```

Pipeline không dùng UVDoc. `orientation_corrected_res.jpg` chỉ là output chạy thủ công của `test_unwarping.py`, không thuộc pipeline phục vụ UI.

## API FastAPI và quản lý file

| Endpoint | Chức năng | Kết quả |
| --- | --- | --- |
| `GET /health` | Kiểm tra service | JSON `status: ok` |
| `POST /process` | Luồng đầy đủ Laravel sử dụng | Ảnh cuối, OCR thô, OCR chuẩn hóa, face crop nếu có |
| `POST /ocr` | OCR trực tiếp ảnh upload, không tiền xử lý | JSON OCR thô |
| `POST /preprocess` | Chỉ tiền xử lý | JPEG kết quả |

Các endpoint nhận `.jpg`, `.jpeg`, `.png`, `.webp`. Upload được lưu tạm trong `ai-engine/tmp/` theo UUID rồi xóa sau request. Pipeline timeout sau 300 giây.

`pipeline.py` dùng output cố định trong `ai-engine/output/`; `app.py` dùng `asyncio.Lock()` để một thời điểm chỉ có một pipeline, tránh request ghi đè kết quả của nhau.

## Pipeline tiền xử lý

| Bước | Script | Input | Output |
| --- | --- | --- | --- |
| 1 | `test_orientation_rotate.py` | ảnh upload | `orientation_corrected.jpg` |
| 2 | `test_document_crop.py` | orientation output | `document_crop.jpg` |
| 3 | `test_deskew.py` | crop output | `document_deskew.jpg` |
| 4 | `test_glare.py` | deskew output | `document_deglare.jpg` |
| 5 | `test_deblur.py` | deglare output | `document_deblur.jpg` |
| 6 | `test_enhance.py` | deblur output | `document_final.jpg` |
| 7 | `test_ocr.py` | `document_final.jpg` | `ocr_raw_result.json` |
| 8 | `test_structured_extraction.py` | OCR thô + `document_final.jpg` | `ocr_structured_result.json` |

### 1. Chỉnh hướng toàn ảnh

Model `PP-LCNet_x1_0_doc_ori` chạy ONNX Runtime CPU, phân loại hướng `0`, `90`, `180`, `270` độ. Nó chỉ sửa ảnh bị quay ngang/lộn ngược; không crop và không deskew thẻ đặt xéo. Dự đoán `0` vẫn ghi `orientation_corrected.jpg`, nên ảnh có thể giống ảnh input.

### 2. Xác định bốn góc, crop và nắn phối cảnh

Đây là thị giác máy tính OpenCV, không dùng model detect giấy tờ chuyên biệt. Ảnh được thu nhỏ về cạnh dài tối đa 1200 px để detect rồi quy đổi tọa độ về ảnh gốc. Bốn góc luôn sắp theo trên-trái, trên-phải, dưới-phải, dưới-trái.

Ứng viên tứ giác được chấm theo diện tích, tỷ lệ cạnh thẻ ngang, độ chữ nhật, cạnh đối diện, góc và mức sát biên. Các nhánh được thử theo thứ tự:

1. Canny, morphology và contour tứ giác toàn ảnh.
2. HSV mask xanh/xanh xám cho CCCD.
3. HSV tìm ROI thô, rồi dò cạnh/tứ giác trong ROI.
4. `minAreaRect` trên tập cạnh khi contour không khép kín.
5. Mask màu ấm cho giấy tờ vàng/be.
6. `minAreaRect` với HSV mask CCCD gentle, medium và regular.
7. Khung đáng ngờ/bị che: HSV bounding box, khôi phục từ ba cạnh hoặc đường Hough phối cảnh.
8. Tách giấy tờ khỏi nền đồng nhất bằng màu nền LAB ở vùng biên.
9. Fallback bố cục chữ: PP-OCRv5 detector chỉ lấy polygon text, `minAreaRect` bao quanh polygon để suy ra khung; không dùng nội dung chữ.
10. Không có khung đáng tin cậy: giữ toàn ảnh để tránh crop mất thông tin.

Sau khi có bốn góc, `cv2.getPerspectiveTransform` và `cv2.warpPerspective` biến tứ giác thành hình chữ nhật phẳng. Đây là bước crop thực tế và nắn phối cảnh.

| File debug | Ý nghĩa |
| --- | --- |
| `document_detection.jpg` | Ảnh nguồn có bốn góc được chọn |
| `document_mask.jpg` | Mask/edge của nhánh detect đang dùng |
| `document_crop.jpg` | Ảnh sau perspective crop |

### 3. Deskew

`test_deskew.py` dùng Canny + `HoughLinesP`, lấy trung vị góc của các đường gần ngang. Góc có trị tuyệt đối dưới `0,3°` giữ nguyên. Khi xoay, canvas được mở rộng, nội suy cubic và `BORDER_REPLICATE` giúp hạn chế mất mép.

### 4. Khử chói

`test_glare.py` tìm vùng sáng/ít bão hòa trong HSV, đóng morphology và lọc connected component không hợp lý. Các vùng chói hợp lệ được giảm sáng trên kênh `L` của LAB. File debug: `glare_mask.jpg`, `glare_detection.jpg`.

### 5. Khử mờ

`test_deblur.py` đo phương sai Laplacian và áp dụng Unsharp Mask theo mức mờ. Đây là sharpen thích ứng; không thể tái tạo hoàn toàn chi tiết đã mất vì rung máy hoặc out-of-focus nặng.

### 6. Resize và tăng tương phản

`test_enhance.py` chỉ thu nhỏ ảnh rộng hơn 1600 px bằng `INTER_AREA`, không phóng to ảnh nhỏ. CLAHE được áp dụng lên kênh sáng LAB; kết quả JPEG chất lượng 95 là `document_final.jpg`.

## OCR trên ảnh cuối

Khi chạy pipeline đầy đủ, OCR luôn đọc `output/document_final.jpg`, không đọc trực tiếp ảnh upload.

### Detect: PP-OCRv5

`ocr_service.py` dùng `PP-OCRv5_server_det` qua PaddleX + ONNX Runtime CPU. Model trả polygon bốn điểm và `detection_confidence` cho từng vùng chữ; vùng được sắp trên-xuống, trái-phải.

Polygon được nới 6%, rồi perspective warp thành dòng chữ thẳng trước recognition để hạn chế cắt mất dấu tiếng Việt.

### Recognition: VietOCR

Recognizer là `VietOCR vgg_transformer`, chạy PyTorch CPU với cấu hình tiếng Việt. Model được khởi tạo một lần và có lock khi inference.

`ocr_raw_result.json` giữ toàn bộ text, `confidence` recognition của VietOCR, `detection_confidence` của PP-OCRv5 và polygon nguồn:

```json
{
  "status": "completed",
  "engine": {
    "detector": "PP-OCRv5_server_det",
    "recognizer": "VietOCR vgg_transformer",
    "recognition_language": "vi"
  },
  "lines": [
    {
      "index": 0,
      "text": "...",
      "confidence": 0.0,
      "detection_confidence": 0.0,
      "polygon": [[0, 0], [0, 0], [0, 0], [0, 0]]
    }
  ]
}
```

Hai confidence chỉ là tín hiệu tham khảo, không bảo đảm dữ liệu OCR luôn chính xác.

## Chuẩn hóa OCR và crop khuôn mặt

`test_structured_extraction.py` gọi `build_structured_result()` trong `structured_extraction.py`. Bước này dùng JSON OCR thô và polygon đã có, không OCR lại ảnh.

### Phân loại giấy tờ

Ưu tiên nhận diện theo tiêu đề OCR: CCCD, giấy phép lái xe hoặc giấy đăng ký xe. Khi tiêu đề bị lóa/mất, dùng tổ hợp nhãn:

- `Hạng/Class` → giấy phép lái xe.
- Tổ hợp số giấy tờ, giới tính, quê quán → CCCD.
- Ít nhất ba nhãn trong chủ xe, nhãn hiệu, số loại, số máy, số khung, biển số → giấy đăng ký xe.

Fallback này chỉ chọn bộ trường phù hợp, không tạo dữ liệu OCR mới.

### Trích và chuẩn hóa trường

1. Chuẩn hóa Unicode NFC/khoảng trắng; tạo khóa tìm kiếm không dấu để nhận ra nhãn thiếu dấu hoặc sai nhẹ do OCR.
2. Lấy giá trị cùng dòng, bên phải nhãn hoặc ở vùng gần kề. Mỗi field giữ `label`, `raw_value`, `normalized_value`, confidence và `source_line_indexes` để truy vết.
3. Địa chỉ nhiều dòng dùng polygon làm anchor: lấy giá trị cùng hàng, rồi nối các hàng dưới trong cùng vùng ngang. Ngưỡng khoảng cách dựa trên chiều cao trung vị dòng OCR, không cố định theo pixel.
4. Field ở cột trái không làm dừng địa chỉ đang nối ở cột phải; chỉ field/ngày/xác thực trong đúng vùng ngang mới là điểm dừng.
5. Tên chủ xe ưu tiên giá trị cùng dòng hoặc dòng ngay dưới nhãn. Nếu nhãn tên không đọc được, fallback bố cục chỉ xét dòng tên hợp lệ ngay trên nhãn địa chỉ. Giá trị tên chỉ cho phép chữ, khoảng trắng, dấu gạch nối/nháy để không lấy nhầm mã số/địa chỉ.
6. Biển số chỉ nhận nếu có mẫu chữ-số hợp lệ và nằm bên dưới nhãn `Biển số đăng ký`. Hậu tố loại xe OCR dính sau biển số bị loại khỏi giá trị biển số.
7. Chuẩn hóa bảo thủ: ngày đổi `-` thành `/`; số giấy tờ chỉ giữ chữ số khi đủ sáu số; tên viết hoa đổi Title Case; địa chỉ bỏ ký tự nhiễu cuối như `);`. Không đủ căn cứ thì để trống, không tự bịa giá trị.

| Loại giấy tờ | Trường cố định trên UI |
| --- | --- |
| CCCD | Số/No, họ tên, giới tính, ngày sinh, quốc tịch, quê quán, nơi thường trú, có giá trị đến |
| Giấy phép lái xe | Số/No, họ tên, ngày sinh, quốc tịch, nơi cư trú, hạng/class, có giá trị đến |
| Giấy đăng ký xe | Tên chủ xe, địa chỉ, nhãn hiệu, số loại, số máy, số khung, màu sơn, hoạt động trong phạm vi, biển số, số chỗ ngồi, giá trị đến ngày, công suất, loại xe, dung tích |

Các dòng không thuộc trường cố định vẫn có trong `unmapped_raw_lines`; UI đồng thời hiển thị toàn bộ OCR thô để đối chiếu.

### Crop khuôn mặt

Sau chuẩn hóa text, YuNet (`cv2.FaceDetectorYN`) chạy trên `document_final.jpg`, chọn khuôn mặt có điểm/diện tích phù hợp lớn nhất, thêm padding 15% và lưu `output/faces/face_current.jpg`. Nếu không có model hoặc không phát hiện mặt, pipeline vẫn thành công với `face_crop.status` tương ứng `model_not_available` hoặc `not_detected`.

`ocr_structured_result.json` gồm `document`, `fields`, `verification`, `face_crop`, `unmapped_raw_lines` và đường dẫn OCR thô.

## Kết quả `/process` và lưu tại Laravel

`POST /process` trả:

- `processed_image`: JPEG cuối dạng base64.
- `ocr.raw`: dữ liệu OCR thô.
- `ocr.structured`: dữ liệu OCR chuẩn hóa.
- `face_crop`: JPEG base64 khi YuNet phát hiện mặt; ngược lại `null`.

`AiImageProcessor` kiểm tra payload và giải mã base64. `DocumentController` sau đó:

1. Lưu ảnh gốc vào `storage/app/private/documents/originals/`.
2. Lưu ảnh kết quả vào `storage/app/private/documents/processed/`.
3. Lưu face crop vào `storage/app/private/documents/faces/` nếu có và ghi `face_crop.stored_path` trong JSON chuẩn hóa.
4. Lưu `ocr_raw_data`, `ocr_structured_data`, đường dẫn ảnh và trạng thái vào bảng MySQL `image_documents`.
5. Nếu AI engine/pipeline/JSON lỗi, đổi trạng thái thành `failed` và ghi chi tiết vào `storage/logs/laravel.log`.

Ảnh private được trả qua route Laravel với `Cache-Control: private, no-store`.

## Chạy pipeline thủ công

Từ thư mục `ai-engine`:

```bash
source venv/bin/activate
python pipeline.py input/cccd_1.jpg
```

Mỗi lượt chạy sẽ ghi đè output debug chung:

```text
output/orientation_corrected.jpg
output/document_detection.jpg
output/document_mask.jpg
output/document_crop.jpg
output/document_deskew.jpg
output/document_deglare.jpg
output/document_deblur.jpg
output/document_final.jpg
output/ocr_raw_result.json
output/ocr_structured_result.json
```

Output chung chỉ dành cho debug cục bộ. Dữ liệu của từng lượt upload trên UI được Laravel lưu riêng trong private storage và MySQL.
