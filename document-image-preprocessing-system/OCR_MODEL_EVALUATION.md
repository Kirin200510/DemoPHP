# Đánh giá và lựa chọn OCR model

## Mục tiêu

OCR phải đọc tối đa nội dung tiếng Việt trên ảnh giấy tờ đã qua pipeline tiền xử lý, đồng thời giữ tọa độ polygon để chuẩn hóa dữ liệu theo bố cục. Kết quả OCR không được xem là dữ liệu đã xác thực: confidence chỉ hỗ trợ đối chiếu với ảnh gốc.

## Tổ hợp đang chạy

```text
document_final.jpg
  -> PP-OCRv5_server_det: polygon + detection confidence
  -> perspective warp từng polygon
  -> VietOCR vgg_transformer: text tiếng Việt + recognition confidence
  -> structured_extraction.py: nhận diện loại giấy tờ, gán field, ghép địa chỉ nhiều dòng
```

`pipeline.py` luôn OCR trên `output/document_final.jpg`, không OCR trực tiếp ảnh gốc. `ocr_service.py` khóa inference để bảo vệ việc tái sử dụng model trong process hiện tại.

## So sánh theo vai trò

| Thành phần | Model | Input | Output | Lý do chọn | Giới hạn |
| --- | --- | --- | --- | --- | --- |
| Text detection | `PP-OCRv5_server_det` | Ảnh đã tiền xử lý | Polygon 4 điểm, detection confidence | Định vị dòng/vùng chữ và giữ bố cục để chuẩn hóa | Không tự đọc text; polygon sai hoặc thiếu sẽ làm recognizer mất nội dung |
| Text recognition | `VietOCR vgg_transformer` | Ảnh dòng chữ đã nắn thẳng | Text, recognition confidence | Pretrained hướng tiếng Việt, phù hợp hơn recognizer Latin tổng quát trong các lần kiểm thử của project | Không tự phát hiện text; có thể sai trên ảnh nhỏ, mờ, chói, chữ bị che hoặc số sát nhau |

## Cách triển khai hiện tại

### PP-OCRv5_server_det

Detector được PaddleX khởi tạo với `device="cpu"` và `engine="onnxruntime"`. Mỗi polygon được nới nhẹ, sau đó `cv2.getPerspectiveTransform`/`cv2.warpPerspective` nắn thành một ảnh dòng chữ trước khi gửi sang recognizer. Bước nới biên giúp giảm nguy cơ cắt mất dấu tiếng Việt sát mép polygon.

Thông thường detector chạy tại bước OCR chính. Riêng khi `test_document_crop.py` không xác định được giấy tờ bằng các fallback hình học, nhánh OCR text-layout có thể gọi detector sớm để bao vùng chữ và suy ra khung giấy tờ. Sau khi crop hoàn tất, detector vẫn chạy lại trên `document_final.jpg` cho OCR chính; đây là fallback định vị giấy tờ, không phải một recognizer thứ hai.

### VietOCR vgg_transformer

Recognizer dùng cấu hình `vgg_transformer`, chạy CPU qua PyTorch. Lần đầu thiếu cache, service khởi tạo model pretrained rồi lưu config/weight vào `ai-engine/model-cache/vietocr/`; các lần sau tái sử dụng cache và instance đã nạp trong process.

## Dữ liệu trả về

Raw OCR gồm `full_text` và từng dòng: `text`, `confidence`, `detection_confidence`, `polygon`. `structured_extraction.py` dùng các dòng này để:

- Nhận diện CCCD, giấy phép lái xe hoặc giấy chứng nhận đăng ký xe từ tiêu đề/nhãn.
- Ghép nhãn với giá trị cùng hàng, bên phải hoặc ở hàng tiếp theo theo polygon.
- Ghép địa chỉ nhiều dòng trong vùng bố cục phù hợp.
- Chuẩn hóa bảo thủ số, ngày và một số nhiễu ký tự; vẫn giữ `raw_value`, `source_line_indexes` và `unmapped_raw_lines` để truy vết.
- Dùng YuNet độc lập với OCR để crop khuôn mặt khi phát hiện được.

Kết quả OCR/chuẩn hóa cần được khách hàng đối chiếu và có thể chỉnh sửa trước khi gửi hồ sơ để nhân viên xác thực.
