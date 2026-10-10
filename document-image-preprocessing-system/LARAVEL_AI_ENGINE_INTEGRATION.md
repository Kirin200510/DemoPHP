# Tích hợp Laravel với AI Engine

Laravel là lớp giao diện, xác thực/phân quyền và lưu trữ hồ sơ. FastAPI trong `ai-engine/` chỉ xử lý ảnh, OCR và chuẩn hóa. Luồng tích hợp đang sử dụng endpoint `POST /process`, không dùng `/preprocess`.

## Luồng upload thực tế

```text
Customer đã đăng nhập
  -> POST /documents
  -> StoreDocumentRequest validate file
  -> lưu ảnh gốc private + tạo ImageDocument (status=processing)
  -> AiImageProcessor POST {AI_ENGINE_URL}/process, multipart field `file`
  -> FastAPI chạy pipeline đầy đủ và trả JSON
  -> Laravel lưu ảnh kết quả, face crop (nếu có), raw OCR và structured OCR
  -> ImageDocument chuyển status=completed
  -> redirect /documents/{document}
```

Nếu AI Engine, payload JSON/base64 hoặc thao tác lưu file lỗi, Laravel chuyển hồ sơ sang `failed`, ghi lỗi kỹ thuật vào `storage/logs/laravel.log` và chỉ báo thông điệp tổng quát trên UI.

## Payload AI Engine được Laravel sử dụng

`AiImageProcessor` yêu cầu payload thành công có cấu trúc sau:

```json
{
  "status": "completed",
  "processed_image": {
    "filename": "document_final.jpg",
    "media_type": "image/jpeg",
    "base64": "..."
  },
  "ocr": {
    "raw": {},
    "structured": {}
  },
  "face_crop": null
}
```

`face_crop` chỉ có `media_type: image/jpeg` và `base64` khi YuNet phát hiện được khuôn mặt. Laravel không dùng các file debug trong `ai-engine/output/` làm lịch sử hồ sơ.

## Các endpoint FastAPI

| Endpoint | Mục đích | Laravel UI sử dụng |
| --- | --- | --- |
| `GET /health` | Kiểm tra AI Engine hoạt động | Không gọi tự động |
| `POST /process` | Tiền xử lý + OCR + chuẩn hóa + face crop; trả JSON/base64 | Có |
| `POST /ocr` | Raw OCR trực tiếp trên ảnh upload, không tiền xử lý | Không |
| `POST /preprocess` | Chạy pipeline và trả riêng JPEG cuối | Không |

AI Engine chỉ nhận `.jpg`, `.jpeg`, `.png`, `.webp`; các request pipeline được tuần tự hóa bởi lock vì output debug dùng tên file cố định. Timeout phía FastAPI là 300 giây; timeout Laravel lấy từ `AI_ENGINE_TIMEOUT` (mặc định 360 giây).

## Thành phần Laravel

| Thành phần | Vai trò thực tế |
| --- | --- |
| `routes/web.php` | Route hồ sơ, ảnh private, review, admin và quick login local/testing |
| `DocumentController` | Lưu file, gọi AI Engine, chuyển trạng thái, kiểm tra Policy và audit log |
| `StoreDocumentRequest` | Chỉ nhận JPG/JPEG/PNG/WEBP, tối đa 10 MB |
| `AiImageProcessor` | HTTP client gọi `/process`, kiểm tra JSON/media type/base64 |
| `ImageDocument` | Metadata, owner/reviewer, trạng thái, path, raw/structured OCR |
| `ImageDocumentPolicy` | Kiểm soát quyền trên từng hồ sơ và face crop |
| `AdminController` | Xem/gán role, bật/tắt tài khoản và xem audit log |

## Route ứng dụng

Tất cả route hồ sơ và admin nằm sau middleware `auth` và `active`.

| Method | URL | Route name | Kiểm tra chính |
| --- | --- | --- | --- |
| `GET` | `/` | `documents.create` | Người dùng đã đăng nhập |
| `POST` | `/documents` | `documents.store` | Policy `create`, throttle 10/phút |
| `GET` | `/documents/{document}` | `documents.show` | Policy `view` |
| `GET` | `/documents/{document}/original` | `documents.original` | Policy `view` |
| `GET` | `/documents/{document}/processed` | `documents.processed` | Policy `view` |
| `GET` | `/documents/{document}/face-crop` | `documents.face-crop` | Policy `viewFaceCrop` |
| `PUT` | `/documents/{document}/ocr` | `documents.ocr.update` | Chủ hồ sơ, status hợp lệ |
| `POST` | `/documents/{document}/submit-review` | `documents.submit-review` | Chủ hồ sơ, status hợp lệ |
| `POST` | `/documents/{document}/verify` | `documents.verify` | Processor/Reviewer, `pending_review` |
| `POST` | `/documents/{document}/request-resubmission` | `documents.request-resubmission` | Processor/Reviewer, `pending_review` |
| `GET` | `/admin/users` | `admin.users` | Role `admin` |
| `PUT` | `/admin/users/{user}/access` | `admin.users.access` | Role `admin` |
| `GET` | `/admin/audit-logs` | `admin.audit-logs` | Role `admin` |

Fortify đăng ký các route login/register/forgot-password/reset-password của package. `POST /login/quick/{role}` chỉ hoạt động trong môi trường `local` hoặc `testing`.

## Database và private storage

MySQL không lưu binary ảnh. Bảng `image_documents` lưu owner, trạng thái và các đường dẫn/JSON sau:

| Dữ liệu | Nơi lưu |
| --- | --- |
| Ảnh gốc | `storage/app/private/documents/originals/` |
| Ảnh kết quả | `storage/app/private/documents/processed/` |
| Khuôn mặt crop | `storage/app/private/documents/faces/` |
| OCR raw | `image_documents.ocr_raw_data` (JSON) |
| OCR structured | `image_documents.ocr_structured_data` (JSON) |
| Chủ hồ sơ/reviewer/trạng thái/lý do | cột `user_id`, `reviewed_by`, `reviewed_at`, `review_notes`, `status` |

Ảnh được trả qua controller sau Policy, với `Cache-Control: private, no-store` và `X-Content-Type-Options: nosniff`. Không tạo symlink public cho private storage.

## Cấu hình cần có

```dotenv
AI_ENGINE_URL=http://127.0.0.1:8000
AI_ENGINE_CONNECT_TIMEOUT=5
AI_ENGINE_TIMEOUT=360
```

`config/services.php` đọc các biến này. Các bước chạy đầy đủ, database, Vite và SMTP được ghi trong [RUN_PROJECT.md](RUN_PROJECT.md).
