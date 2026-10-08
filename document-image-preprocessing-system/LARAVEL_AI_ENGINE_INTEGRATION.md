# Tích hợp AI Engine với Laravel

Tài liệu này mô tả cách source Laravel tích hợp với FastAPI trong `ai-engine` để người dùng tải ảnh lên giao diện, xử lý ảnh và xem/tải kết quả.

## Kiến trúc

```text
Trình duyệt
    │ POST /documents (image)
    ▼
Laravel DocumentController
    ├─ Validate upload
    ├─ Lưu ảnh gốc vào private storage
    ├─ Tạo ImageDocument: status = processing
    ▼
AiImageProcessor
    │ POST http://127.0.0.1:8000/preprocess (multipart field: file)
    ▼
FastAPI AI Engine
    │ JPEG cuối cùng
    ▼
Laravel
    ├─ Lưu JPEG kết quả vào private storage
    ├─ Cập nhật MySQL: processed_path, status = completed
    └─ Redirect đến trang kết quả
```

## Thành phần Laravel

| Thành phần | Vai trò |
| --- | --- |
| `routes/web.php` | Khai báo upload, xem chi tiết, xem ảnh gốc và ảnh kết quả |
| `DocumentController` | Điều phối HTTP: lưu ảnh, gọi AI, cập nhật database, trả giao diện |
| `StoreDocumentRequest` | Kiểm tra file ảnh đầu vào |
| `AiImageProcessor` | Client HTTP chuyên gọi FastAPI `/preprocess` |
| `ImageDocument` | Model lưu metadata/path và trạng thái xử lý |
| `resources/views/documents/create.blade.php` | Form chọn ảnh, preview và nút xử lý |
| `resources/views/documents/show.blade.php` | So sánh ảnh gốc với ảnh sau xử lý |

## Route giao diện

| Method | URL | Tên route | Chức năng |
| --- | --- | --- | --- |
| `GET` | `/` | `documents.create` | Trang chọn/tải ảnh |
| `POST` | `/documents` | `documents.store` | Upload và xử lý; giới hạn 10 request/phút |
| `GET` | `/documents/{document}` | `documents.show` | Trang kết quả |
| `GET` | `/documents/{document}/original` | `documents.original` | Đọc ảnh gốc từ private storage |
| `GET` | `/documents/{document}/processed` | `documents.processed` | Đọc ảnh kết quả từ private storage |

## Luồng xử lý upload

### 1. Kiểm tra ảnh

`StoreDocumentRequest` yêu cầu trường `image`:

- Bắt buộc có file.
- Phải là ảnh hợp lệ.
- Chỉ nhận JPG, JPEG, PNG và WEBP.
- Dung lượng tối đa 10 MB.

### 2. Lưu ảnh gốc và tạo bản ghi

Controller lưu file gốc vào disk `local`:

```text
storage/app/private/documents/originals/<tên-ngẫu-nhiên>.<phần-mở-rộng>
```

Sau đó Laravel tạo bản ghi trong bảng `image_documents`:

```text
original_path = đường dẫn tương đối của ảnh gốc
processed_path = null
status = processing
```

### 3. Gọi FastAPI

`AiImageProcessor` mở file upload và gửi multipart request. Tên field bắt buộc là `file`, vì FastAPI khai báo `file: UploadFile = File(...)`.

```text
POST {AI_ENGINE_URL}/preprocess
Accept: image/jpeg
Content-Type: multipart/form-data

file: <ảnh người dùng tải lên>
```

Client có timeout kết nối mặc định 5 giây và timeout toàn bộ request mặc định 360 giây. Phản hồi lỗi HTTP của AI engine được chuyển thành exception; response thành công phải có `Content-Type: image/jpeg` và body không rỗng.

### 4. Lưu ảnh kết quả và cập nhật MySQL

Khi AI trả JPEG, Laravel ghi file tại:

```text
storage/app/private/documents/processed/<uuid>.jpg
```

Sau đó cập nhật bản ghi:

```text
processed_path = documents/processed/<uuid>.jpg
status = completed
```

Nếu có lỗi trong lúc xử lý, status chuyển thành `failed`, user được chuyển về trang chi tiết cùng thông báo lỗi. Lỗi kỹ thuật được ghi vào Laravel log.

## Database và storage

### Bảng `image_documents`

| Cột | Ý nghĩa |
| --- | --- |
| `id` | Mã bản ghi |
| `original_path` | Đường dẫn ảnh gốc trong private storage |
| `processed_path` | Đường dẫn ảnh kết quả; `null` nếu chưa thành công |
| `status` | `processing`, `completed` hoặc `failed` |
| `created_at`, `updated_at` | Thời điểm tạo/cập nhật |

MySQL **không lưu binary JPEG**. Database chỉ lưu metadata và đường dẫn; file thật nằm trong `storage/app/private`. Điều này tránh làm database phình to và cho phép Laravel kiểm soát việc trả ảnh qua controller.

Các response ảnh có header `Cache-Control: private, no-store` và `X-Content-Type-Options: nosniff`.

## Cấu hình

Trong `.env`:

```dotenv
AI_ENGINE_URL=http://127.0.0.1:8000
AI_ENGINE_CONNECT_TIMEOUT=5
AI_ENGINE_TIMEOUT=360
```

Laravel đọc các giá trị này qua `config/services.php`, không gọi `env()` trực tiếp trong code ứng dụng.

## Khởi động local

Mở hai terminal riêng:

```bash
# Terminal 1: AI engine
cd /home/user/document-image-preprocessing-system/ai-engine
source venv/bin/activate
uvicorn app:app --host 127.0.0.1 --port 8000
```

```bash
# Terminal 2: Laravel
cd /home/user/document-image-preprocessing-system
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8001
```

Mở `http://127.0.0.1:8001` để dùng giao diện. Không chạy Laravel ở cổng 8000 vì cổng này dành cho AI engine.

## Migration cần thiết

Trước khi chạy, thực hiện:

```bash
php artisan migrate
```

Bảng cần có cột `processed_path`. Migration `2026_10_07_043151_ensure_processed_path_exists_on_image_documents_table.php` là migration sửa lỗi an toàn: chỉ bổ sung cột nếu database cũ chưa có cột này. Không dùng `migrate:fresh` trên database có dữ liệu vì lệnh đó xóa bảng.

## Kiểm tra dữ liệu đã lưu

```bash
mysql -h 127.0.0.1 -u laravel -p document_image_preprocessing
```

```sql
SELECT id, original_path, processed_path, status, created_at
FROM image_documents
ORDER BY id DESC;
```

Một ảnh xử lý thành công có `status = 'completed'`, `processed_path` khác `NULL`, và file tương ứng phải tồn tại trong `storage/app/private/documents/processed/`.

## Lưu ý vận hành

- Pipeline FastAPI hiện xử lý tuần tự vì dùng output trung gian có tên cố định.
- Upload/AI processing chạy đồng bộ trong HTTP request; người dùng chờ cho đến khi pipeline trả kết quả.
- Các route hiện không có middleware xác thực. Nếu triển khai cho nhiều người dùng hoặc ảnh giấy tờ thật, cần bổ sung đăng nhập, authorization và giới hạn quyền xem từng bản ghi.
- Không đưa `storage/app/private/documents` ra public bằng symlink; ảnh được trả qua controller để giữ private.

