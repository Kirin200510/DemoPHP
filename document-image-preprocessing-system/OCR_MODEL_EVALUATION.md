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

## Phụ lục khảo sát các lựa chọn OCR khác

Phần phụ lục này được bổ sung để so sánh với tổ hợp đang chạy; không thay đổi lựa chọn hoặc luồng triển khai ở các phần phía trên. Đây là khảo sát theo tài liệu của nhà phát hành và kiến trúc model, **không phải** kết quả benchmark trên bộ ảnh giấy tờ của project. Không model nào trong bảng dưới được cài thêm hoặc được gọi bởi `ocr_service.py`.

### Phạm vi so sánh

OCR của project là kiến trúc hai giai đoạn: detector cần trả polygon để giữ bố cục cho `structured_extraction.py`, còn recognizer cần đọc tốt tiếng Việt có dấu. Vì vậy không đánh giá một recognizer đơn lẻ như một phương án thay thế hoàn chỉnh nếu nó không có detector đi kèm.

| Phương án | Phạm vi | Điểm mạnh khi đối chiếu với hệ thống hiện tại | Hạn chế/rủi ro cho project |
| --- | --- | --- | --- |
| **Hiện tại: PP-OCRv5 detector + VietOCR vgg_transformer** | Detector và recognizer tách rời | Detector trả polygon 4 điểm; VietOCR pretrained cho tiếng Việt, nên phù hợp mục tiêu giữ dấu tiếng Việt và ghép dữ liệu theo vị trí | Hai runtime PaddleX/ONNX Runtime và PyTorch; tốc độ CPU phụ thuộc số dòng text; vẫn có thể sai khi ảnh mờ, chói hoặc bị che |
| **PP-OCRv5 detector + PP-OCRv5 recognizer** | Hai giai đoạn PaddleOCR | Cùng hệ sinh thái, dễ cấu hình end-to-end và có các recognizer multilingual/Latin chính thức | Danh sách ngôn ngữ của recognizer PP-OCRv5 được khảo sát không liệt kê tiếng Việt; không thay VietOCR nếu chưa có benchmark tiếng Việt có dấu hoặc checkpoint/finetune phù hợp |
| **EasyOCR** | End-to-end: detector CRAFT/DBNet và recognizer | Có hỗ trợ mã ngôn ngữ `vi`; một thư viện có thể chạy cả detection và recognition, thuận tiện dựng baseline | Dùng recognizer Latin dùng chung nhiều ngôn ngữ; cần kiểm chứng riêng độ chính xác dấu tiếng Việt, polygon/layout và hiệu năng CPU trước khi thay pipeline hiện tại |
| **Tesseract 5 với `vie.traineddata`** | OCR engine kèm page segmentation | Có language data tiếng Việt chính thức, nhẹ và quen thuộc khi chạy CPU; thích hợp baseline/offline đơn giản | Đầu ra mặc định thiên về text/box theo page segmentation, không thay trực tiếp detector polygon hiện tại; cần tự xử lý phân đoạn, góc nghiêng và ghép layout nhiều dòng |
| **docTR** | Framework ghép detector và recognizer, ví dụ DBNet + CRNN/PARSeq | Hỗ trợ tách hoặc ghép hai giai đoạn, detector có hỗ trợ tài liệu nghiêng; thuận lợi nếu sau này cần thử nhiều kiến trúc trong cùng framework | Model pretrained mặc định/vocabulary không được chọn riêng cho tiếng Việt trong project; cần chuẩn bị vocabulary/checkpoint tiếng Việt và benchmark trước khi tích hợp |
| **TrOCR** | Recognizer Transformer VisionEncoderDecoder | Kiến trúc Transformer mạnh cho nhận dạng ảnh dòng chữ; phù hợp để thử nghiệm/finetune recognizer khi đã có line crop | Không có detector trong model này; checkpoint phổ biến không mặc định bảo đảm tiếng Việt. Cần detector hiện tại và dữ liệu gán nhãn tiếng Việt để đánh giá hoặc finetune |

### Phân tích theo từng lựa chọn

#### PP-OCRv5 recognition

PaddleOCR cung cấp recognizer PP-OCRv5 cho nhiều ngôn ngữ, đồng thời detector PP-OCRv5 đang chạy của project đã tương thích với cùng hệ sinh thái. Đây là phương án có chi phí thay đổi kiến trúc thấp nhất. Tuy nhiên, tài liệu PP-OCRv5 multilingual được khảo sát liệt kê các model Latin và một số nhóm ngôn ngữ khác, nhưng không liệt kê tiếng Việt. Vì yêu cầu chính của project là giữ dấu tiếng Việt, PP-OCRv5 recognition chỉ phù hợp làm ứng viên benchmark dự phòng, không thay thế VietOCR mặc định khi chưa đo trên dữ liệu thực.

#### EasyOCR

EasyOCR cung cấp detector CRAFT hoặc DBNet và recognizer trong một API; danh sách language của recognizer Latin thế hệ 2 có `vi`. Điểm thuận tiện là có thể tạo baseline end-to-end nhanh. Điểm đánh đổi là model Latin phục vụ nhiều ngôn ngữ và project sẽ phải kiểm tra lại độ chính xác của dấu, số định danh, chuỗi biển số và polygon theo góc nghiêng trước khi dùng cho chuẩn hóa dữ liệu.

#### Tesseract 5

Tesseract có language data `vie.traineddata` chính thức và có thể chạy LSTM engine trên CPU. Đây là lựa chọn hữu ích để tạo baseline chi phí thấp cho ảnh giấy tờ đã crop phẳng, đặc biệt khi cần so sánh text thuần. Tuy nhiên, project cần polygon bốn điểm cho từng dòng để ghép field và địa chỉ nhiều dòng; vì thế Tesseract không phải thay thế trực tiếp cho PP-OCRv5 detector nếu không bổ sung tầng layout/detection riêng.

#### docTR

docTR cung cấp `ocr_predictor` gồm detector và recognizer có thể hoán đổi; tài liệu của docTR cho biết detector hỗ trợ tài liệu xoay/nghiêng. Đây là lợi thế khi nghiên cứu nhiều tổ hợp như DBNet + PARSeq. Dù vậy, checkpoint và vocabulary phải được kiểm tra cho tiếng Việt, nên rủi ro triển khai cao hơn việc giữ detector hiện tại và recognizer VietOCR.

#### TrOCR

TrOCR là recognizer Transformer dạng VisionEncoderDecoder, nhận ảnh text crop và sinh chuỗi ký tự; nó không thực hiện text detection. Vì vậy TrOCR chỉ có thể được thử ở đúng vị trí của VietOCR sau `perspective_warp_line()`, không thể thay cả pipeline. Nó chỉ đáng cân nhắc khi project có tập dữ liệu dòng chữ tiếng Việt đã gán nhãn để benchmark hoặc finetune.

### Kết luận khảo sát

Tổ hợp hiện tại vẫn là lựa chọn phù hợp nhất cho yêu cầu hiện tại: **PP-OCRv5_server_det** giữ polygon/bố cục, còn **VietOCR vgg_transformer** ưu tiên recognition tiếng Việt. Nếu cần khảo sát thực nghiệm tiếp theo, thứ tự hợp lý là:

1. Dùng **Tesseract `vie`** làm baseline text trên cùng các line crop.
2. Dùng **EasyOCR `vi`** làm baseline end-to-end để so sánh detection + recognition.
3. Chỉ thử PP-OCRv5 recognition, docTR hoặc TrOCR sau khi có bộ benchmark chứa ảnh CCCD, GPLX và đăng ký xe với ground truth tiếng Việt; đánh giá riêng CER/WER, tỷ lệ giữ dấu, tỷ lệ đọc đúng số/biển số và độ chính xác polygon.

Việc thay model chỉ nên được quyết định sau benchmark trên cùng ảnh đầu vào và cùng bước tiền xử lý; không suy luận model tốt hơn chỉ từ confidence hoặc kết quả của một vài ảnh.

### Nguồn tham khảo khảo sát

- [PaddleOCR: PP-OCRv5 multilingual recognition](https://paddlepaddle.github.io/PaddleOCR/latest/en/version3.x/algorithm/PP-OCRv5/PP-OCRv5_multi_languages.html)
- [VietOCR repository](https://github.com/pbcquoc/vietocr)
- [EasyOCR repository](https://github.com/JaidedAI/EasyOCR)
- [Tesseract language data](https://tesseract-ocr.github.io/tessdoc/Data-Files.html)
- [docTR model guide](https://mindee.github.io/doctr/using_doctr/using_models.html)
- [Hugging Face TrOCR documentation](https://huggingface.co/docs/transformers/model_doc/trocr)
