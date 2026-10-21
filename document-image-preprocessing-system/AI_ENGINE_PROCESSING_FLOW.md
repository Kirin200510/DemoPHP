# Luồng xử lý AI Engine

Đối chiếu source ngày **21/10/2026**. Laravel gọi `/process` cho một ảnh hoặc `/process-batch` cho nhiều ảnh. Các bước tiền xử lý trong mỗi job chạy tuần tự; nhiều job có thể chạy đồng thời với workspace riêng. Sau OCR/chuẩn hóa từng ảnh, Laravel chỉ gọi riêng `/document-sides/match` khi upload đúng hai ảnh và cả hai xử lý thành công.

## 1. Sơ đồ tổng quát

```text
Người dùng chọn ảnh trên Laravel UI
        |
        v
DocumentController lưu ảnh gốc private
        |
        v
AiImageProcessor -> POST /process hoặc /process-batch
        |
        v
workspace riêng ai-engine/tmp/<job-id>/ cho từng ảnh
        |
        v
orientation -> document crop/perspective -> deskew
        -> deglare -> deblur -> enhance
        -> document_final.jpg
        |
        +-> OCR provider (mặc định PP-OCRv5 -> VietOCR qua process pool)
        +-> structured extraction theo nhãn + polygon
        +-> MRZ + barcode local + YuNet face detection/crop
        |
        v
Laravel lưu ảnh mã hóa, OCR JSON và trạng thái hồ sơ vào storage/database
        |
        +-> đúng hai ảnh thành công: đối chiếu -> chỉ gộp nếu matched
```

Pipeline không dùng UVDoc. `orientation_corrected.jpg` chỉ là output trung gian để kiểm tra chiều; nếu model dự đoán ảnh đã đúng chiều thì file có thể giống ảnh đầu vào. Ảnh Laravel hiển thị sau cùng là `document_final.jpg`.

## 2. API FastAPI

| Endpoint | Công dụng |
| --- | --- |
| `GET /health` | Trả `status: ok` để kiểm tra server |
| `GET /metrics`, `GET /metric` | Metrics in-memory: số job/slot, thời gian, thành công/thất bại và OCR provider; header no-store |
| `POST /process` | Một ảnh, multipart `file`; trả JSON/base64 |
| `POST /process-batch` | Nhiều ảnh, multipart `files` lặp lại; trả kết quả độc lập theo thứ tự |
| `POST /document-sides/match` | JSON array hai structured results; đối chiếu CCCD, không OCR lại |
| `POST /ocr` | OCR trực tiếp ảnh upload, không chạy tiền xử lý |
| `POST /preprocess` | Chạy cùng pipeline đầy đủ nhưng chỉ trả file JPEG cuối, không trả OCR JSON |

AI Engine nhận `.jpg`, `.jpeg`, `.png`, `.webp` với dung lượng **không vượt quá 10 MiB**. Engine kiểm tra dung lượng, mở ảnh bằng Pillow để xác minh nội dung ảnh, sau đó chạy quality gate dùng điểm chi tiết Laplacian, tương phản, tỷ lệ vùng cháy sáng và một heuristic hình học nhẹ từ Canny/morphology. Heuristic chấm contour theo diện tích, tỷ lệ cạnh, độ lấp đầy `minAreaRect` và mức chạm biên; nếu không còn ứng viên khung tối thiểu, ảnh bị từ chối trước pipeline. Ảnh không đạt trả HTTP 422 với mã `image_too_blurry_or_low_contrast`, `image_severely_overexposed` hoặc `document_frame_not_detected`. Tiêu chí khung được đặt bảo thủ: nó không thay thế detector 4 góc và không bỏ qua các fallback crop đầy đủ. Chuẩn **98 KiB–3 MiB** và **350–4096 px** không áp lên ảnh upload; chúng được áp ở bước resize/enhance để tạo `document_final.jpg`. Mỗi ảnh qua API được đặt trong `ai-engine/tmp/<job-id>/input.<ext>` và có `output/` riêng. API truyền `PIPELINE_OUTPUT_DIR` cho các script, dọn workspace khi kết thúc; `/preprocess` dọn sau khi gửi file bằng background task. Output dùng chung `ai-engine/output/` chỉ còn là mặc định khi chạy CLI.

`processing_slot()` dùng semaphore mặc định **2 job đồng thời** (`AI_MAX_CONCURRENT_JOBS`, giới hạn 1–4) cho từng FastAPI process; `/ocr` và `/preprocess` cũng đi qua slot này. OCR nội bộ chạy qua process pool với `AI_LOCAL_OCR_WORKERS` mặc định bằng số job, không vượt số job. OCR ngoài và chuẩn hóa chạy qua thread. Mỗi worker local giữ model và `_ocr_predict_lock` riêng. Sáu bước tiền xử lý trong một job vẫn tuần tự. Timeout 300 giây chỉ áp cho subprocess tiền xử lý, không phải tổng thời gian OCR hay cả batch.

FastAPI batch dùng `asyncio.gather`, giữ thứ tự đầu vào và trả lỗi từng ảnh trong `documents`; top-level `completed` không bảo đảm mọi ảnh thành công. Trần 6 ảnh/lượt nằm ở Laravel, không ở endpoint FastAPI này. Header `X-OCR-Provider` chọn provider cho request; thiếu header thì engine đọc file state dùng chung rồi fallback biến môi trường. Xem [OCR_MODEL_EVALUATION.md](OCR_MODEL_EVALUATION.md).

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

**Script:** `test_document_crop.py`<br>
**Input:** `<OUTPUT_DIR>/orientation_corrected.jpg`<br>
**Output chính:** `<OUTPUT_DIR>/document_crop.jpg`

`OUTPUT_DIR` là `PIPELINE_OUTPUT_DIR` nếu được truyền, nếu không là thư mục `output` mặc định của các script. Những đường dẫn `output/...` minh họa bên dưới phải hiểu tương đối với output của job khi chạy API.

Ảnh được thu nhỏ sao cho cạnh dài tối đa 1200 px khi nhận diện để giảm chi phí xử lý. Sau khi tìm được góc, tọa độ được quy đổi ngược về kích thước ảnh gốc. Bốn góc luôn được chuẩn hóa theo thứ tự:

```text
P1: trên-trái     P2: trên-phải
P4: dưới-trái     P3: dưới-phải
```

#### Mô hình sử dụng

Bước xác định bốn góc không dùng YOLO, segmentation model hoặc model AI chuyên phát hiện giấy tờ. Phần lớn cơ chế là thuật toán OpenCV và luật hình học. Riêng fallback cuối về bố cục chữ mới gọi **detector** PP-OCRv5 để lấy polygon các vùng chữ; recognizer OCR không được dùng để quyết định bốn góc.

#### Cơ chế tìm bốn góc

**Nhánh chính — biên và contour:**

1. Chuyển ảnh sang grayscale, Gaussian blur, Canny (`40/120`) và morphology close kernel `7x7` để nối các cạnh giấy tờ bị đứt.
2. Lấy contour bằng `RETR_LIST`, xấp xỉ với `approxPolyDP` có epsilon bằng 2% chu vi.
3. Chỉ giữ contour lồi có đúng bốn đỉnh, sắp theo thứ tự trên-trái, trên-phải, dưới-phải, dưới-trái.
4. Chấm điểm những ứng viên còn lại, lấy ứng viên có điểm lớn nhất.

**Bộ lọc và chấm điểm ứng viên:**

| Tiêu chí | Quy tắc hiện tại | Vai trò |
| --- | --- | --- |
| Diện tích contour | Từ 8% đến 95% diện tích ảnh detect | Bỏ nhiễu nhỏ và contour gần bằng toàn khung. |
| Tỷ lệ dài/rộng | 1,35–2,20 theo `minAreaRect` | Giữ ứng viên có dáng thẻ/giấy tờ. |
| Độ lấp đầy hình chữ nhật | Tối thiểu 0,45 diện tích `minAreaRect` | Loại vùng méo, rời rạc hoặc chỉ là chi tiết bên trong. |
| Hai cạnh đối diện | Độ tương đồng của mỗi cặp tối thiểu 0,60 | Hạn chế hình thang quá méo và cạnh giả. |
| Góc | Sai số góc trung bình so với 90° không quá 35° | Kiểm tra tứ giác gần với khung giấy tờ. |
| Mép khung hình | Đỉnh trong biên 1% ảnh bị nhân điểm 0,7 | Không ưu tiên contour chạm mép ảnh. |

Điểm cuối là tích của tỷ lệ diện tích, độ chữ nhật, độ tương đồng hai cặp cạnh, độ gần với tỷ lệ tham chiếu 1,586 và điểm góc. Các ngưỡng là luật tổng quát để phân biệt giấy tờ với hình chữ nhật khác, không được gắn với một file ảnh cụ thể.

**Nhánh theo màu và ROI:**

Nếu nhánh cạnh không có tứ giác phù hợp, hệ thống tạo mask HSV cho vùng xanh/xanh xám của CCCD và thử contour tứ giác từ mask. Khi mask chỉ tạo được vùng màu thô, ROI của vùng đó được mở rộng rồi chạy lại detector cạnh bên trong ROI.

Một mask HSV riêng cho giấy tờ vàng/be như giấy phép lái xe dùng Hue 10–45, Saturation từ 10, Value từ 80 và morphology close kernel `17x17`. Mask CCCD dùng Hue 70–155, Saturation từ 10, Value từ 40; fallback của nó thử kernel close 5, 15 và 31 rồi chọn ứng viên tốt nhất. Cả hai dùng `minAreaRect`, diện tích 3–90% ảnh, tỷ lệ 1,35–2,20, độ chữ nhật tối thiểu 0,40 và loại khung vượt biên ảnh. Nhánh vàng/be không trim contour để tránh cắt nội dung sát mép; nhánh CCCD chỉ trim 4% biên khi kết quả vẫn giữ ít nhất 92% chiều rộng/cao của khung ban đầu. Nếu ứng viên CCCD thắng, crop được nới 10% vì mask màu có thể bỏ sót vùng sáng hoặc viền nhựa trong.

#### Fallback hình học

Khi contour không đủ bốn cạnh rõ ràng, các fallback được thử theo điều kiện trong script. Nhánh hình học còn được chạy nếu ứng viên trước đó có dấu hiệu chạm biên/bọc quá nhiều ảnh, không chỉ khi hoàn toàn thiếu ứng viên. Hình dưới chỉ minh họa cơ chế, không phải kết quả detection của ảnh người dùng.

![Các tình huống fallback hình học](ai-engine/fallback-geometric-examples.png)

1. **`minAreaRect` từ cạnh ngoài hoặc mask màu.** Dùng contour ngoài cùng của Canny hoặc contour mask màu để tạo hình chữ nhật xoay. Nhánh edge chỉ nhận vùng 3–80% ảnh; vùng nhỏ chạm mép bị loại để không nhầm ảnh chân dung/khối chữ thành cả giấy tờ.
2. **Hộp bao HSV thô.** Khi một cạnh bị che hoặc mask màu không khép kín, dùng bbox của vùng HSV bao ngoài; bbox chỉ được nhận nếu tỷ lệ, diện tích và khoảng cách đến biên ảnh hợp lý.
3. **Khôi phục từ ba cạnh và Hough Lines phối cảnh.** Khi hộp HSV thô không có kết quả, script tính cả ứng viên từ ba cạnh và ứng viên từ các cặp đường Hough. Nếu trước đó chưa có ứng viên, ưu tiên nhánh ba cạnh rồi mới nhánh phối cảnh. Nếu đang thay ứng viên đáng ngờ và cả hai nhánh đều có kết quả, chỉ ưu tiên ba cạnh khi diện tích lớn hơn ứng viên phối cảnh trên 1,35 lần.
4. **Bảo vệ khung ảnh đầy đủ.** Các kiểm tra dải biên, hộp chữ nằm bên trong ảnh đã sát khung thẻ và xung đột với ứng viên hình học nhỏ hơn đáng kể có thể giữ nguyên toàn ảnh, padding 0 để tránh cắt mất nội dung.
5. **Tách giấy tờ khỏi nền đồng nhất.** Ước lượng màu nền bằng median của bốn dải biên trong LAB, lấy vùng có khoảng cách màu lớn hơn nền, morphology close rồi kiểm tra lại diện tích, tỷ lệ và độ chữ nhật.
6. **Fallback bố cục chữ.** Chỉ sau khi nhánh cạnh/màu/hình học thất bại, `detect_text_lines()` của PP-OCRv5 lấy polygon ít nhất sáu dòng chữ. `minAreaRect` bao các polygon đó phải có tỷ lệ 1,35–2,20 và chiếm 4–65% ảnh; khung chữ được nới 18% để suy ra khung giấy tờ. Detector này chỉ dùng polygon, không dùng nội dung hay dữ liệu nhận dạng OCR.
7. **Fallback toàn ảnh an toàn.** Nếu không còn ứng viên, hoặc ứng viên chỉ là một dải dọc/ngang chạm biên trong khi ảnh nguồn đã có tỷ lệ thẻ, hệ thống giữ bốn góc toàn ảnh để không crop nhầm và làm mất nội dung.

#### Từ bốn góc đến ảnh crop

Sau khi chọn khung, hệ thống dùng padding mặc định 3%; fallback OCR text-layout dùng 2%, fallback toàn khung dùng 0%, còn fallback màu CCCD hoặc nhánh phối cảnh có thể dùng 10% theo quy tắc phía trên. `cv2.getPerspectiveTransform` tạo homography và `cv2.warpPerspective` biến đổi tứ giác thành ảnh chữ nhật phẳng. Đây là bước vừa crop vừa nắn phối cảnh, kết quả là `document_crop.jpg`.

| File | Mục đích |
| --- | --- |
| `output/document_detection.jpg` | Vẽ bốn góc cuối cùng trên ảnh gốc. |
| `output/document_mask.jpg` | Mask/cạnh của nhánh được dùng khi có mask debug. |
| `output/document_crop.jpg` | Ảnh đã crop và chỉnh phối cảnh. |

### 3.3 Deskew, khử chói, khử mờ và enhance

- Deskew dùng Canny + HoughLinesP để ước lượng góc đường văn bản, sau đó xoay với canvas mở rộng.
- Khử chói tìm vùng sáng/ít bão hòa trong HSV, rồi giảm sáng kênh L của LAB.
- Khử mờ đo độ sắc nét bằng Laplacian và áp dụng unsharp mask thích ứng.
- Enhance dùng CLAHE trên kênh sáng LAB, sau đó chuẩn hóa ảnh kết quả. Ảnh rộng hơn 1600 px được thu nhỏ theo tỷ lệ; ảnh có cạnh nhỏ hơn 350 px được phóng to theo tỷ lệ nếu vẫn không vượt 4096 px, hoặc thêm viền trắng khi cần. JPEG được thử quality từ 95 đến 50; nếu còn lớn hơn 3 MiB thì tiếp tục thu nhỏ theo tỷ lệ. Nếu JPEG nhỏ hơn 98 KiB, engine thêm JPEG COM segment hợp lệ để đạt mức file tối thiểu mà không thay đổi điểm ảnh. Kết quả `document_final.jpg` luôn có mỗi chiều trong **350–4096 px** và dung lượng **98 KiB–3 MiB**.

## 4. OCR trên ảnh cuối

Luồng `/process`, `/process-batch` và CLI đầy đủ OCR trên `<OUTPUT_DIR>/document_final.jpg`. Endpoint `/ocr` là luồng riêng, OCR ảnh upload trực tiếp sau validate/quality gate. Nhà cung cấp OCR được chọn theo request; các mục 4.1–4.2 mô tả nhánh mặc định `local_hybrid`.

### 4.1 Detect: PP-OCRv5

`ocr_service.py` dùng `PP-OCRv5_server_det` thông qua PaddleX/ONNX Runtime CPU. Detector được nạp một lần và tái sử dụng trong từng process worker OCR nội bộ. Detector trả polygon bốn điểm và confidence của vùng chữ. Mỗi polygon được nới nhẹ rồi perspective warp thành dòng thẳng để recognition, giúp hạn chế mất dấu tiếng Việt.

### 4.2 Recognition: VietOCR

Recognizer cố định là `VietOCR vgg_transformer`, chạy PyTorch CPU với cấu hình tiếng Việt. Model được khởi tạo một lần trong mỗi worker và khóa khi inference. Khi gọi API, `pipeline.py` chỉ chạy sáu bước tiền xử lý trong process con với `PIPELINE_SKIP_OCR=1`; OCR nội bộ chạy trong process pool thường trực, chuẩn hóa chạy trong thread của FastAPI. Model chỉnh chiều và detector dùng ở fallback crop thuộc các process tiền xử lý riêng, không phải instance OCR chính trong pool. Khi chạy `python pipeline.py ...` thủ công mà không đặt cờ skip, pipeline chạy thêm OCR và chuẩn hóa bằng hai script CLI, không tái sử dụng process pool của API.

Khi chạy CLI, `ocr_raw_result.json` lưu toàn bộ dòng đọc được, text, confidence recognition, `detection_confidence` và polygon. API trả dữ liệu đó trong `ocr.raw`, không lưu file JSON debug này làm lịch sử. Confidence chỉ là mức tin cậy tham khảo, không phải bảo đảm dữ liệu chính xác.

## 5. Chuẩn hóa dữ liệu và khuôn mặt

Trong API, `app.py` gọi `build_structured_result()` trong `structured_extraction.py`; khi chạy thủ công, `test_structured_extraction.py` gọi cùng hàm. Bước này dùng OCR thô + polygon, không OCR lại.

1. Làm sạch dòng bằng `ocr_labels.py`: chuẩn hóa Unicode NFC, khoảng trắng/nhãn, giữ raw text và các nhãn khớp để truy vết. MRZ được phân tích từ dòng nguồn trước khi loại chúng khỏi phần gán trường thông thường.
2. Nhận diện loại giấy tờ dựa trên tiêu đề và tổ hợp nhãn: CCCD, giấy phép lái xe hoặc giấy đăng ký xe.
3. Ghép nhãn với giá trị cùng dòng/bên phải; địa chỉ nhiều dòng được ghép theo anchor polygon và khoảng cách thích nghi theo chiều cao dòng.
4. Chuẩn hóa bảo thủ: không đủ căn cứ thì để trống; giữ dòng không gán trường trong `unmapped_raw_lines`.
5. Nhận diện khuôn mặt bằng `cv2.FaceDetectorYN` (YuNet) trên `document_final.jpg`, thêm padding và trả ảnh crop nếu phát hiện được.

Các bộ trường cố định:

- CCCD `card_version=legacy/new`: có schema/nhãn riêng cho số CCCD hoặc số định danh cá nhân, họ tên, ngày sinh, giới tính, quốc tịch, quê quán/nơi cư trú, nơi đăng ký khai sinh, đặc điểm nhân dạng, ngày cấp/hết hạn tùy phiên bản và dữ liệu đọc được. Trường cố định chưa nhận diện được có `extraction_status=not_detected`, không được coi là bằng chứng ghép mặt.
- Giấy phép lái xe: số, họ tên, ngày sinh, quốc tịch, nơi cư trú, hạng/class, giá trị đến.
- Đăng ký xe: tên chủ xe, địa chỉ, nhãn hiệu, số loại, số máy, số khung, màu sơn, hoạt động trong phạm vi, biển số, số chỗ ngồi, giá trị đến ngày, công suất, loại xe, dung tích.

## 6. MRZ, barcode và ghép hai mặt CCCD

### MRZ

`mrz_parser.py` ghép các polygon cùng hàng và phân tích TD1 theo bố cục ba hàng, mỗi hàng 30 ký tự. Giữ `raw_lines`, `normalized_lines`, `candidate_lines`, `corrections`, `warnings`, `format_valid`. Khi có ảnh, `mrz_image.py` dùng bằng chứng hình dạng để sửa nhầm filler `<`; gợi ý chưa đủ bằng chứng nằm ở candidate, không tự thay văn bản tin cậy.

Profile định danh hiện tại đọc hàng đầu `IDVNM`: vị trí **6–14** là 9 số sau tiền tố CCCD, vị trí **16–27** là số định danh 12 chữ số (đếm từ 1). Có thể giữ identifier từ hàng đầu đủ vị trí dù MRZ chưa hoàn chỉnh; hàm ghép mặt yêu cầu MRZ đọc được/hợp lệ theo điều kiện riêng. `checksum_validated` hiện luôn **false**: `format_valid` chỉ kiểm tra bố cục/cấu trúc, chưa xác thực checksum ICAO hoặc tính thật của thẻ.

### Barcode local

`barcode_service.py` dùng `zxing-cpp` đọc cả ảnh cuối và ảnh upload gốc trong luồng API. Khi quét toàn ảnh không có mã hợp lệ, thử vùng **30% phía trên**, phóng lớn tối đa **3 lần**, giới hạn chiều rộng đích **2400 px**; chỉ thử nếu hệ số phóng lớn lớn hơn 1. Đây là fallback vùng cố định, không phải detector barcode tổng quát cho mọi hướng/bố cục. Loại trùng bằng cặp format + raw bytes.

Mỗi record giữ `format`, `source_stage`, `content_type`, `byte_length`, `symbology_identifier`, `raw_bytes_base64`, `raw_text`, `parsed`. Các profile hiện hỗ trợ:

- `identity_number_only`: payload văn bản đúng 12 chữ số.
- `identity_keyed_json`: JSON khóa định danh/nhân thân thuộc danh sách hỗ trợ, kiểm tra trùng khóa và kiểu giá trị.
- `vn_identity_qr_pipe_7`: **chỉ QRCode**, bảy trường phân cách `|`; cho phép thêm các phần **rỗng** ở cuối, không nhận phần bổ sung có nội dung. Kiểm tra số định danh, số cũ tùy chọn, họ tên, ngày sinh, giới tính, nơi cư trú, ngày cấp.

Payload vượt 8192 byte, không UTF-8, cấu trúc lạ hoặc giá trị không hợp lệ trả `parsed.status=unsupported`, fields rỗng. Trạng thái barcode `decoded` chỉ nói rằng đã đọc được ký hiệu; không bảo đảm parse được thông tin cá nhân. Payload PDF417 nhị phân của mẫu CCCD 2016 đang ở nhóm unsupported; chưa có thuật toán chuyển payload này thành số CCCD/họ tên/ngày sinh. Không đánh đồng việc lưu Base64 với giải mã thông tin định danh. CLI chuẩn hóa hiện chỉ truyền ảnh cuối, không tự truyền lại ảnh gốc như API.

### Đối chiếu và gộp

`document_pairing.py` xác định một mặt trước/một mặt sau từ loại giấy tờ, trường OCR, MRZ và dấu hiệu PDF417; thứ tự hai ảnh có thể đảo. Chỉ chấp nhận cặp khi số 12 chữ số mặt trước khớp MRZ theo vị trí nêu trên hoặc barcode mặt sau thuộc profile đã parse. Kiểm tra thêm các giá trị họ tên/ngày sinh/giới tính khi các nguồn cung cấp chúng. Nguồn định danh mâu thuẫn, không rõ mặt hoặc thiếu dữ liệu đều chặn gộp.

`/document-sides/match` trả 200/`matched` với `matching_method=mrz`, `barcode` hoặc `mrz_and_barcode`. Không khớp trả 400/`ERR_PAIR_MISMATCH`; thiếu bằng chứng/sai mặt/mâu thuẫn nguồn trả 422 với mã nghiệp vụ tương ứng. `build_citizen_identity_card_pair_result()` chỉ dựng dữ liệu ghép sau đối chiếu ở endpoint: ưu tiên giá trị mặt trước, mặt sau/barcode chỉ bổ sung trường còn trống, giữ `source_side`, `source_type` và mọi dòng OCR hai mặt.

Trước khi POST kết quả sang endpoint ghép, `AiImageProcessor::normalizePairingPayload()` khôi phục các trường object rỗng thành `{}` thay vì PHP `[]`, giữ các danh sách là array; schema FastAPI vẫn yêu cầu dictionary. Lỗi phản hồi/HTTP sai được Laravel phân biệt bằng `ERR_PAIR_INVALID_RESPONSE`, tách khỏi lỗi dịch vụ `ERR_PAIR_SERVICE_UNAVAILABLE`.

Laravel chỉ tự gọi bước này khi đúng hai ảnh cùng lượt upload đều completed. Upload 3–6 ảnh chỉ xử lý độc lập, không tự tìm cặp giữa các ảnh. Ghép mặt thành công là kiểm tra nhất quán dữ liệu, không phải xác thực giấy tờ thật. Chi tiết lưu hồ sơ chính/mặt phụ: [LARAVEL_AI_ENGINE_INTEGRATION.md](LARAVEL_AI_ENGINE_INTEGRATION.md).

## 7. Payload trả về cho Laravel

```json
{
  "status": "completed",
  "processed_image": {"filename": "document_final.jpg", "media_type": "image/jpeg", "base64": "..."},
  "ocr": {"raw": {}, "structured": {}},
  "face_crop": {"media_type": "image/jpeg", "base64": "..."}
}
```

`face_crop` là `null` nếu không phát hiện mặt. Laravel giải mã payload, lưu ảnh vào `storage/app/private/documents/processed/` và `faces/`, lưu hai JSON vào `image_documents`, rồi hiển thị ảnh cuối, các trường chuẩn hóa và toàn bộ dòng OCR theo quyền. Hai khối JSON kỹ thuật chỉ hiển thị cho Admin.

Nếu xử lý một ảnh hoặc payload/base64 lỗi, Laravel đánh dấu riêng hồ sơ đó `failed` và ghi chi tiết kỹ thuật vào log. UI có thông báo riêng cho ảnh không đạt quality gate, dịch vụ không phản hồi, payload lỗi hoặc lỗi ứng dụng. Lỗi ghép mặt không chuyển các hồ sơ đã OCR thành `failed`; giữ kết quả riêng và thông báo đối chiếu.

## 8. Chạy thủ công

```bash
cd /home/user/document-image-preprocessing-system/ai-engine
source venv/bin/activate
python pipeline.py input/cccd_1.jpg
```

CLI mặc định ghi debug vào `ai-engine/output/` và có thể ghi đè ở lượt chạy sau. Có thể truyền `PIPELINE_OUTPUT_DIR` để tách output CLI. API luôn dùng workspace theo job và dọn sau response; không lấy debug CLI làm lịch sử. Hồ sơ thật nằm trong private storage/database do Laravel quản lý. UI dùng `/process` hoặc `/process-batch`, không dùng `/preprocess`.

## 9. Hiệu năng và giới hạn vận hành

- Mỗi FastAPI process có semaphore và process pool riêng. Mặc định 2 job, tối đa 4; tăng số Uvicorn worker nhân cả số model và bộ nhớ, không tự tổng hợp metrics.
- Model PP-OCRv5/VietOCR của OCR chính được tái sử dụng trong từng worker local. Không có số liệu benchmark mới hoặc cam kết độ chính xác/tốc độ chung trong tài liệu này.
- `/metric` và `/metrics` trả cùng dữ liệu in-memory, reset khi restart, mẫu thời gian tối đa 100 lượt. Metrics đếm đoạn xử lý nằm trong slot; không bao gồm thời gian chờ semaphore hoặc mọi lỗi validation trước slot. `lock_active` nghĩa là các slot đã đầy, không phải global lock một job như thiết kế cũ.
- Provider chọn theo header, file state do Admin lưu, rồi biến `OCR_PROVIDER`. Bốn lựa chọn đã có: `local_hybrid`, `fpt_ai_reader`, `vnpt_ekyc`, `external_ekyc`. Provider ngoài không thay thế local barcode/MRZ/face crop và các bước tiền xử lý; không có fallback tự động sang provider khác.
- Laravel retry có giới hạn lỗi kết nối/5xx, batch giữ ảnh thành công và chỉ thử lại ảnh lỗi hệ thống. Dashboard Admin tính theo audit event; không dùng trực tiếp metrics FastAPI để tính tỷ lệ lỗi lâu dài.
- Chưa có queue chạy nền cho xử lý hồ sơ, distributed scheduler hoặc cơ chế gom metrics đa worker. Tổng thời gian batch và lượng RAM phải được cân đối với timeout Laravel, PHP và reverse proxy.
