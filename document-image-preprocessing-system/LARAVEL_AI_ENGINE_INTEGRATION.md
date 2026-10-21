# Tích hợp Laravel với AI Engine

Đối chiếu source ngày **21/10/2026**. Laravel quản lý giao diện, tài khoản, quyền và hồ sơ; FastAPI xử lý ảnh/OCR. Các điểm đối chiếu chính: `routes/web.php`, `DocumentController`, `AiImageProcessor`, `SystemSetting`, `ai-engine/app.py`.

## 1. Luồng upload và lưu kết quả

```text
POST /documents (Customer đã đăng nhập)
  → StoreDocumentRequest: images[] (1–6 ảnh), hoặc image (tương thích một ảnh)
  → mỗi ảnh: mã hóa ảnh gốc, tạo ImageDocument(user_id, processing, expires_at)
  → một ảnh: POST /process, multipart file
  → nhiều ảnh: POST /process-batch, multipart files lặp lại
  → mỗi kết quả độc lập: lưu JPEG/face crop và raw/structured OCR
  → mỗi hồ sơ chuyển completed hoặc failed, ghi audit
  → đúng 2 ảnh và cả 2 thành công: POST /document-sides/match
  → nếu matched: gộp hồ sơ theo mặt trước/mặt sau; nếu không: giữ riêng
```

Laravel chờ HTTP xử lý xong trong request upload; chưa dispatch job xử lý ảnh vào queue Laravel. Batch không tự suy ra quan hệ giữa ảnh. Một ảnh thành công vẫn được lưu nếu ảnh khác thất bại. Với một ảnh, redirect đến trang chi tiết; với nhiều ảnh, redirect về trang chủ kèm thông báo tổng hợp.

Mỗi ảnh tối đa `10 * 1024 * 1024` byte, đuôi JPG/JPEG/PNG/WEBP. Laravel kiểm tra file trước lưu; FastAPI kiểm tra lại nội dung bằng Pillow và quality gate. FastAPI `/process-batch` không áp trần 6 ảnh như FormRequest Laravel. Mẫu Nginx giới hạn `client_max_body_size 12m` cho **toàn bộ request**, nên 6 ảnh hợp lệ riêng lẻ vẫn có thể vượt giới hạn proxy/PHP.

## 2. Hợp đồng API FastAPI

| Endpoint | Input | Output / bên gọi |
| --- | --- | --- |
| `GET /health` | Không | `status: ok`, tên service; không kiểm tra model/vendor |
| `GET /metrics`, `GET /metric` | Không | Metrics in-memory, provider hiện được chọn; không cache |
| `POST /process` | Multipart `file` | Một kết quả đầy đủ; Laravel dùng cho một ảnh |
| `POST /process-batch` | Multipart `files` lặp lại | `documents` theo thứ tự đầu vào; Laravel dùng cho nhiều ảnh |
| `POST /document-sides/match` | JSON array đúng 2 structured results | Ghép CCCD độc lập với OCR; Laravel gọi sau khi đủ điều kiện |
| `POST /ocr` | Multipart `file` | Raw OCR; có validate/quality gate nhưng không tiền xử lý |
| `POST /preprocess` | Multipart `file` | JPEG cuối; không trả OCR |

Các endpoint xử lý OCR hỗ trợ header tùy chọn `X-OCR-Provider`. Request Laravel dùng provider lấy từ `SystemSetting::ocrProvider()`. FastAPI không có middleware xác thực tài khoản tương đương Laravel; cấu hình chạy mặc định bind loopback, không coi các endpoint này là API công khai đã có xác thực.

### Kết quả một ảnh thành công

```json
{
  "status": "completed",
  "processed_image": {
    "filename": "document_final.jpg",
    "media_type": "image/jpeg",
    "base64": "..."
  },
  "ocr": {"raw": {}, "structured": {}},
  "face_crop": null
}
```

Khi có mặt, `face_crop` là object `{ "media_type": "image/jpeg", "base64": "..." }`. `AiImageProcessor` kiểm tra kiểu dữ liệu, media type và base64 không rỗng trước khi lưu. Structured OCR gồm `document`, `fields`, `mrz`, `barcodes`, `text_cleaning`, `verification`, `face_crop`, `all_ocr_lines`, `unmapped_raw_lines` và metadata nguồn.

### Kết quả batch

```json
{
  "status": "completed",
  "document_count": 2,
  "documents": [
    {"status": "completed", "processed_image": {"media_type": "image/jpeg", "base64": "..."}, "ocr": {"raw": {}, "structured": {}}, "face_crop": null},
    {"status": "failed", "http_status": 422, "detail": {"code": "image_too_blurry_or_low_contrast", "message": "..."}}
  ]
}
```

`status: completed` ở cấp batch nghĩa là đã thu thập kết quả các ảnh, không có nghĩa mọi ảnh đều thành công. Laravel yêu cầu danh sách kết quả có đúng số lượng và thứ tự tương ứng.

### Đối chiếu và gộp CCCD

AI Engine nhận diện một mặt trước/một mặt sau, đọc số 12 chữ số trên mặt trước và so với MRZ hợp lệ hoặc barcode mặt sau đã parse được số định danh. Thứ tự upload có thể đảo. Nếu có thêm họ tên/ngày sinh/giới tính từ MRZ/barcode thì kiểm tra các trường tương ứng; nguồn mâu thuẫn sẽ chặn gộp.

| HTTP | `status` / mã nghiệp vụ | Xử lý ở Laravel |
| --- | --- | --- |
| 200 | `matched`, có `front_input_index`, `back_input_index`, `paired_normalized_ocr` | Chọn hồ sơ mặt trước làm hồ sơ chính và lưu dữ liệu ghép |
| 400 | `not_matched`, `ERR_PAIR_MISMATCH` | Giữ hai hồ sơ riêng, báo không khớp |
| 422 | `needs_review`, `ERR_PAIR_SIDE_INVALID` | Chưa xác định đúng hai mặt |
| 422 | `needs_review`, `ERR_PAIR_INSUFFICIENT_EVIDENCE` | Thiếu số/MRZ/barcode định danh đủ tin cậy |
| 422 | `needs_review`, `ERR_PAIR_CONFLICTING_EVIDENCE` | Nguồn định danh mâu thuẫn |

Laravel chỉ gộp sau khi kiểm tra payload thành công đầy đủ. `ocr_raw_data` của từng ảnh vẫn giữ riêng; hồ sơ chính lưu `individual_normalized_ocr`, `paired_normalized_ocr`, `document_sides`, `pairing`, rồi dùng fields ghép để hiển thị. Mặt sau nhận `merged_into_document_id`; trang chủ ẩn mặt phụ, mở chi tiết mặt phụ sẽ chuyển sang hồ sơ chính sau Policy. Trước khi gửi JSON ghép mặt, client khôi phục object rỗng `{}` tại các trường mapping, đặc biệt `barcodes.records[*].parsed.fields`. Lỗi dịch vụ tạo `ERR_PAIR_SERVICE_UNAVAILABLE` / `side_matching_unavailable`; lỗi response tạo `ERR_PAIR_INVALID_RESPONSE` / `side_matching_invalid_response`, exception HTTP có status code. Cả hai giữ OCR đã lưu và không gộp. Submit/verify/request-resubmission trên hồ sơ chính đồng bộ trạng thái sang các mặt phụ.

## 3. Workspace, đồng thời và timeout

Mỗi ảnh qua API có `ai-engine/tmp/<job-id>/input.<ext>` và `output/` riêng. `pipeline.py` nhận `PIPELINE_INPUT`, `PIPELINE_OUTPUT_DIR`, `PIPELINE_SKIP_OCR=1`; sáu bước tiền xử lý chạy tuần tự trong job. FastAPI chuẩn hóa bằng thread, OCR nội bộ qua `ProcessPoolExecutor`, OCR ngoài qua thread. Workspace được dọn sau khi đóng gói response; `/preprocess` dọn bằng background task sau khi gửi JPEG.

- `AI_MAX_CONCURRENT_JOBS`: mặc định 2, chặn trong khoảng 1–4, tính cho từng FastAPI process.
- `AI_LOCAL_OCR_WORKERS`: mặc định bằng giới hạn job, trong khoảng 1 đến số job; mỗi worker có model/cache instance và lock inference riêng.
- Timeout **subprocess tiền xử lý**: 300 giây. Không phải timeout tổng cho cả OCR và toàn bộ batch.
- Laravel `AI_ENGINE_TIMEOUT`: fallback trong config là 360 giây; `.env.example` đặt 600 giây.
- `AI_ENGINE_BATCH_TIMEOUT`: fallback 600 giây; HTTP batch dùng giá trị lớn hơn giữa timeout đơn và batch.

Chạy nhiều Uvicorn worker sẽ nhân giới hạn job, số model và bộ nhớ; metrics của từng process không tự tổng hợp. Hệ thống chưa có queue phân tán hoặc cơ chế điều phối đa máy.

## 4. Chọn OCR provider khi đang chạy

`SystemSetting` hỗ trợ `local_hybrid`, `fpt_ai_reader`, `vnpt_ekyc`, `external_ekyc`. Admin lưu tại `/admin/ocr-vendor`: cập nhật `system_settings`, ghi audit `ocr.provider_updated` và thay file state nguyên tử. Nếu không đồng bộ file được, transaction database bị rollback.

Provider Laravel gửi đi được lấy từ database hợp lệ, fallback về `config('services.ai_engine.default_ocr_provider')`. FastAPI ưu tiên header của request; nếu không có header, đọc file state hợp lệ, sau đó mới fallback `OCR_PROVIDER` trong môi trường. File state mặc định `storage/app/private/ocr-provider.json`; hai service chạy khác máy/mount cần cấu hình cùng vị trí logic bằng `AI_OCR_PROVIDER_STATE_PATH` và chia sẻ nội dung file.

Đổi provider qua Admin không cần restart. Đổi credential, endpoint, document type FPT hoặc biến môi trường Uvicorn cần khởi động lại process tương ứng. Không fallback tự động sang provider khác khi vendor lỗi. Metrics đọc lựa chọn hiện tại khi gọi lại endpoint, không phải một bảng kết quả tự cập nhật trên Swagger.

Chi tiết hợp đồng từng adapter và cấu hình nằm trong [OCR_MODEL_EVALUATION.md](OCR_MODEL_EVALUATION.md) và [RUN_PROJECT.md](RUN_PROJECT.md).

## 5. Lưu trữ và vòng đời dữ liệu

| Dữ liệu | Vị trí |
| --- | --- |
| Ảnh gốc mã hóa | `storage/app/private/documents/originals/` |
| JPEG cuối mã hóa | `storage/app/private/documents/processed/` |
| Face crop mã hóa | `storage/app/private/documents/faces/` |
| Raw/structured OCR | `image_documents.ocr_raw_data`, `ocr_structured_data` |
| Quan hệ hai mặt | `merged_into_document_id` và metadata ghép trong JSON |
| Provider được lưu | `system_settings` và file `ocr-provider.json` |
| Hạn lưu | `expires_at`, `purged_at` trên từng bản ghi |

`EncryptedDocumentStorage` dùng Laravel Crypt cho **file ảnh**; hai JSON OCR có cast `array` và không được mã hóa ở tầng ứng dụng bằng service này. Giữ `APP_KEY` để đọc lại các file đã mã hóa. Ảnh trả qua Policy/controller với `Cache-Control: private, no-store` và `X-Content-Type-Options: nosniff`; hồ sơ đã purge trả 404 cho ảnh.

`documents:purge-expired` xóa ảnh gốc/ảnh xử lý/face crop và đặt các JSON, processed path thành null; giữ bản ghi, trạng thái, original path tham chiếu và audit. Scheduler mặc định chạy hằng ngày lúc 02:00 theo timezone ứng dụng, chỉ hoạt động khi scheduler Laravel được chạy. `documents:encrypt-legacy-storage --dry-run` đếm dữ liệu ảnh cũ; bỏ `--dry-run` mới thực hiện mã hóa.

## 6. Lỗi, retry và chỉ số vận hành

`AiImageProcessor` retry lỗi kết nối/5xx tối đa `AI_ENGINE_RETRY_ATTEMPTS` (mặc định 3), nghỉ theo số lần thử nhân `AI_ENGINE_RETRY_DELAY_MILLISECONDS`. Batch giữ nguyên ảnh đã thành công và chỉ gửi lại ảnh lỗi `AiEngineUnavailableException`. Ảnh HTTP 422 và response sai cấu trúc/base64 không retry.

Laravel phân loại audit `document.processing_failed` thành `invalid_image`, `ai_engine_unavailable`, `ai_engine_invalid_response`, `application_error`. Dashboard `/admin/processing-metrics` đếm các sự kiện `document.processed`/`document.processing_failed` trong cửa sổ thời gian; trạng thái hồ sơ chuyển sang chờ duyệt/đã duyệt không làm mất lượt thành công. Tỷ lệ lỗi bằng `failed_count / (completed_count + failed_count)`, không tính ảnh bị từ chối và lỗi chưa phân loại vào mẫu số. Khi đạt ngưỡng, service ghi audit/log critical với cooldown; chưa gửi email/Slack alert.

Metrics FastAPI là số liệu in-memory của các đoạn nằm trong `processing_slot()`: lỗi validate trước slot, hàng đợi chờ slot và lỗi phát sinh sau slot không có cùng cách tính với dashboard Laravel. Mẫu thời gian tối đa 100 lượt, reset khi process khởi động lại.

## 7. Route Laravel

Tất cả route hồ sơ/Admin dưới middleware `auth`, `active`.

| Method | URL | Route name / kiểm tra |
| --- | --- | --- |
| GET | `/` | `documents.create`; tối đa 6 hồ sơ theo vai trò |
| GET | `/my-documents` | `documents.index`; owner + documents.view-own, 10 hồ sơ/trang, lọc status |
| GET | `/documents/{document}/report` | `documents.report`; Policy exportReport, tải PDF DomPDF |
| POST | `/documents` | `documents.store`; Policy create, throttle 10/phút |
| GET | `/documents/{document}` | `documents.show`; Policy view |
| GET | `/documents/{document}/original` | `documents.original`; Policy view |
| GET | `/documents/{document}/processed` | `documents.processed`; Policy view |
| GET | `/documents/{document}/face-crop` | `documents.face-crop`; Policy viewFaceCrop |
| PUT | `/documents/{document}/ocr` | `documents.ocr.update`; Policy updateStructuredOcr |
| POST | `/documents/{document}/submit-review` | `documents.submit-review`; Policy submitForReview |
| POST | `/documents/{document}/verify` | `documents.verify`; Policy review |
| POST | `/documents/{document}/request-resubmission` | `documents.request-resubmission`; Policy review |
| GET | `/admin/exports` | `admin.exports.index`; admin + documents.export-management-csv |
| POST | `/admin/exports/csv` | `admin.exports.csv`; admin, kiểm tra permission theo loại báo cáo, throttle 5/phút |
| GET | `/admin/users` | `admin.users`; role admin |
| PUT | `/admin/users/{user}/access` | `admin.users.access`; role admin |
| GET | `/admin/audit-logs` | `admin.audit-logs`; role admin |
| GET | `/admin/processing-metrics` | `admin.processing-metrics`; role admin |
| GET | `/admin/ocr-vendor` | `admin.ocr-vendor`; role admin |
| PUT | `/admin/ocr-vendor` | `admin.ocr-vendor.update`; role admin |

`POST /login/quick/{role}` có throttle 10/phút và controller chỉ hoạt động local/testing. Fortify cung cấp các route auth theo `config/fortify.php`; Laravel còn có health route `/up`. Đã có lịch sử phân trang, PDF và CSV; chưa có route xóa hồ sơ, retry thủ công hoặc xoay ảnh thủ công.

## 8. Cấu hình và TLS

Xem `.env.example`, `config/services.php`, `config/documents.php` và [RUN_PROJECT.md](RUN_PROJECT.md) cho cấu hình đầy đủ. Các biến concurrency/provider credential thuộc môi trường tiến trình Uvicorn; Laravel `.env` không tự truyền sang process Uvicorn đang chạy.

Mẫu Nginx production chuyển HTTP sang HTTPS, TLS 1.2/1.3, HSTS, PHP-FPM với timeout 600 giây. `APP_FORCE_HTTPS=true` buộc URL HTTPS; `SESSION_SECURE_COOKIE=true` chỉ gửi cookie qua HTTPS. Có mẫu local mkcert ở `nginx.local-mkcert.conf.example`. Mỗi mẫu giới hạn cả request 12 MiB; phải cân đối proxy timeout, PHP upload/post limits và batch thực tế. Các file mẫu không chứng minh server đã triển khai TLS.

## 9. Sửa dữ liệu chuẩn hóa và xuất báo cáo

`updateStructuredOcr` cho phép bổ sung field thuộc danh sách theo CCCD, bằng lái hoặc đăng ký xe; không chỉ sửa field OCR đã nhận diện. Field hợp lệ được ghi normalized_value và metadata chỉnh sửa vào fields và paired_normalized_ocr.fields khi có. Field không hỗ trợ trả lỗi validation; lưu model thất bại không báo thành công. Form giữ dữ liệu qua old() khi lỗi. Raw OCR và snapshot individual_normalized_ocr không bị sửa; không tự OCR/đối chiếu lại.

PDF dùng dữ liệu tổng hợp nếu pairing.status=matched, còn lại dùng fields của một ảnh. CSV quản lý có ID khách hàng, loại, trạng thái, is_paired và các mốc thời gian. CSV OCR thêm các cột ocr_{field} theo tập tên field có giá trị trong kết quả lọc, sắp tên cột; có BOM UTF-8 và response no-store. Bỏ mặt phụ, giới hạn 5.000 bản ghi, sort theo id. Hai ngày trống được phép; có from_date thì cần to_date >= from_date; to_date riêng được hỗ trợ. So sánh ngày bao gồm hai đầu và dùng created_at theo timezone ứng dụng (config hiện là UTC).

Giới hạn hiện có cần phân biệt với chức năng đã hoàn thiện: documentType() chỉ đọc document_type, chưa dùng card_version; giá trị chưa xác định là `Unknown` trong controller nhưng nhãn view dùng `unknown`; form CSV hiện thiếu @csrf và các select chưa khôi phục old(). Laravel 13 có kiểm tra same-origin trước token nên form có thể hoạt động trên trình duyệt cùng nguồn, nhưng đường kiểm tra token dự phòng có thể trả 419. Code safeCell thay ký tự xuống dòng/tab và thêm một dấu cách trước các tiền tố công thức; không khẳng định cơ chế này bảo đảm an toàn cho mọi phần mềm bảng tính.
