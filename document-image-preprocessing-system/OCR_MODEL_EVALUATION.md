# Đánh giá model OCR pretrained

## 1. Mô tả đánh giá

Mục tiêu là đọc tối đa nội dung trên CCCD/GPLX đã qua crop, deskew, khử chói, khử mờ và tăng tương phản. OCR phải giữ được polygon của dòng chữ, nội dung tiếng Việt có dấu và điểm tin cậy để hệ thống đánh dấu kết quả cần kiểm tra.

Kết quả thử nghiệm trên các ảnh `4.jpg`, `5.jpg` và `18.jpg` cho thấy recognizer Latin đa ngôn ngữ làm mất hoặc nhầm nhiều dấu tiếng Việt. Do đó hệ thống chốt pipeline ghép hai model pretrained có vai trò riêng:

```text
document_final.jpg
    │
    ├─ PP-OCRv5_server_det: tìm polygon từng dòng chữ
    │
    └─ VietOCR vgg_transformer: đọc tiếng Việt từ từng polygon đã crop
```

## 2. Phân tích model

### PP-OCRv5_server_det — Text detection

PP-OCRv5_server_det chỉ đảm nhận phát hiện vị trí các dòng chữ. Model trả về polygon bốn điểm và confidence detection; ảnh trong polygon được nắn thẳng bằng perspective transform trước khi gửi sang VietOCR.

**Ưu điểm**

- Pretrained, chạy local bằng ONNX Runtime trên CPU.
- Tìm được nhiều vùng chữ nhỏ trên CCCD/GPLX và trả polygon để giữ vị trí trên ảnh.
- Tách detector khỏi recognizer giúp có thể dùng recognizer chuyên tiếng Việt mà không mất khả năng định vị text.

**Hạn chế**

- Không đọc nội dung chữ; không thể tự cung cấp text hay confidence nhận dạng.
- Chất lượng polygon quyết định trực tiếp chất lượng crop dòng chữ đưa vào VietOCR.
- Không tự hiểu field nghiệp vụ như họ tên, số giấy tờ hoặc ngày sinh.

### VietOCR vgg_transformer — Text recognition tiếng Việt

VietOCR là recognizer Transformer pretrained cho tiếng Việt. Model nhận một ảnh dòng chữ đã crop và trả chuỗi văn bản cùng confidence recognition.

**Ưu điểm**

- Huấn luyện hướng đến chữ tiếng Việt, giữ dấu tốt hơn recognizer Latin đa ngôn ngữ trong các thử nghiệm hiện tại.
- Đọc đúng các dòng tiêu đề như `CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM` và `Độc lập - Tự do - Hạnh phúc` trên cả ba ảnh benchmark.
- Chạy local bằng PyTorch CPU; không gửi ảnh giấy tờ sang dịch vụ bên ngoài.

**Hạn chế**

- Không có detector: bắt buộc cần PP-OCRv5_server_det cung cấp polygon.
- Tốn RAM và chậm hơn recognizer ONNX nhẹ khi chạy CPU.
- Vẫn có thể sai chữ rất nhỏ, vùng chói, số sát nhau hoặc ảnh mờ; confidence cao không bảo đảm tuyệt đối đúng.

## 3. Bảng so sánh

| Thành phần | Model được chọn | Input | Output | Vai trò trong hệ thống | Điểm mạnh | Hạn chế chính |
| --- | --- | --- | --- | --- | --- | --- |
| Detection | `PP-OCRv5_server_det` | Ảnh tài liệu đã tiền xử lý | Polygon + detection confidence | Xác định từng vùng/dòng chữ | Định vị text tốt, ONNX CPU | Không đọc nội dung |
| Recognition | `VietOCR vgg_transformer` | Ảnh từng dòng đã crop theo polygon | Text + recognition confidence | Đọc chữ tiếng Việt có dấu | Chuyên tiếng Việt, giữ dấu tốt hơn trong benchmark | Không tự tìm text, chậm hơn trên CPU |

## 4. Quyết định

Hệ thống sử dụng duy nhất tổ hợp sau cho OCR mặc định:

- **Detector:** `PP-OCRv5_server_det`.
- **Recognizer:** `VietOCR vgg_transformer`.

Kết quả raw OCR lưu mỗi dòng gồm `text`, `confidence` (recognition), `detection_confidence` và `polygon`. Chưa tự sửa giá trị cá nhân hoặc tự bóc tách field; các kết quả confidence thấp cần được giao diện hoặc người dùng kiểm tra lại.
