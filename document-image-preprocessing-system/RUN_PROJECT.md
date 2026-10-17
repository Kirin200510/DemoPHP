# Hướng dẫn chạy project

Đối chiếu source ngày **17/10/2026**. Project gồm Laravel UI, database (SQLite hoặc MySQL) và FastAPI AI Engine. Các lệnh local dưới đây chọn Laravel cổng **8001**, AI Engine cổng **8000** để không trùng nhau; đây là ví dụ cấu hình, không phải phát hiện service đang chạy.

## 1. Yêu cầu và cài đặt Laravel

- PHP 8.3 trở lên và Composer; source hiện dùng Laravel 13, Fortify và Spatie Laravel Permission.
- SQLite với `pdo_sqlite`, hoặc MySQL với `pdo_mysql`. `.env.example` mặc định SQLite; MySQL là lựa chọn triển khai, không bắt buộc trong source.
- Node.js/npm phù hợp Vite 8 để build frontend Tailwind CSS 4.
- Venv Python 3.12 và các dependency trong `ai-engine/requirements.txt` cho AI Engine. Các import OCR local vẫn có trong module ngay cả khi chọn vendor ngoài.

```bash
cd /home/user/document-image-preprocessing-system
composer install
npm install
if [ ! -f .env ]; then cp .env.example .env; fi
```

Nếu `APP_KEY` còn trống, tạo key **một lần**:

```bash
php artisan key:generate --no-interaction
```

Giữ nguyên key khi đã có ảnh mã hóa cần đọc lại. Không đưa credential thật vào tài liệu hoặc commit `.env`.

## 2. Cấu hình database và Laravel

### SQLite (mặc định của .env.example)

```dotenv
DB_CONNECTION=sqlite
```

Tạo file database nếu chưa có:

```bash
if [ ! -f database/database.sqlite ]; then touch database/database.sqlite; fi
```

### MySQL (nếu dùng)

Tạo database `document_image_preprocessing`, chạy MySQL bằng công cụ của máy và cấu hình:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=document_image_preprocessing
DB_USERNAME=your-database-user
DB_PASSWORD=your-database-password
```

### Cấu hình chung ở Laravel .env

```dotenv
APP_URL=http://127.0.0.1:8001
AI_ENGINE_URL=http://127.0.0.1:8000
AI_ENGINE_CONNECT_TIMEOUT=5
AI_ENGINE_TIMEOUT=600
AI_ENGINE_BATCH_TIMEOUT=600
AI_ENGINE_RETRY_ATTEMPTS=3
AI_ENGINE_RETRY_DELAY_MILLISECONDS=500
AI_ENGINE_ALERT_WINDOW_MINUTES=15
AI_ENGINE_ALERT_MINIMUM_REQUESTS=5
AI_ENGINE_ALERT_FAILURE_RATIO=0.4
AI_ENGINE_ALERT_COOLDOWN_MINUTES=15
OCR_PROVIDER=local_hybrid
DOCUMENT_RETENTION_DAYS=30
DOCUMENT_RETENTION_PURGE_TIME=02:00
# Khi hai service không dùng cùng đường dẫn mặc định:
# AI_OCR_PROVIDER_STATE_PATH=/shared/ocr-provider.json
```

`config/services.php` fallback timeout một ảnh là **360 giây**, nhưng `.env.example` đặt **600 giây**. Batch timeout fallback 600 giây, HTTP client dùng giá trị lớn hơn giữa timeout đơn và batch. Timeout subprocess tiền xử lý FastAPI cố định 300 giây; OCR/batch có thể mất thêm thời gian.

```bash
php artisan config:clear --no-interaction
php artisan migrate --seed --no-interaction
```

Seeder tạo các role `admin`, `processor`, `reviewer`, `customer`, và ba tài khoản demo:

| Role | Email | Mật khẩu |
| --- | --- | --- |
| Admin | `admin@example.com` | `Admin@12345` |
| Processor | `processor@example.com` | `Processor@12345` |
| Customer | `test@example.com` | `password` |

Seeder dùng `updateOrCreate` và gán lại mật khẩu/role cho ba email này; chỉ seed demo trong môi trường phù hợp, không chạy lại trên tài khoản thật cùng email. Không có tài khoản Reviewer demo riêng. Tài khoản đăng ký mới được gán `customer` và đăng xuất, phải đăng nhập lại.

## 3. Cài và chạy AI Engine

Terminal thứ nhất:

```bash
cd /home/user/document-image-preprocessing-system/ai-engine
# Chỉ tạo venv khi chưa có
python3.12 -m venv venv
source venv/bin/activate
python -m pip install --upgrade pip
pip install -r requirements.txt
export OCR_PROVIDER=local_hybrid
export AI_MAX_CONCURRENT_JOBS=2
export AI_LOCAL_OCR_WORKERS=2
uvicorn app:app --host 127.0.0.1 --port 8000 --reload
```

`AI_MAX_CONCURRENT_JOBS` nhận 1–4, mặc định 2. `AI_LOCAL_OCR_WORKERS` mặc định bằng số job, không vượt số job. Các giới hạn áp dụng cho từng FastAPI process; thêm Uvicorn worker sẽ nhân pool/model và RAM. Sáu bước tiền xử lý tuần tự trong từng job; nhiều job có workspace riêng và có thể chạy đồng thời.

**Các biến export phải thuộc môi trường tiến trình Uvicorn.** Laravel `.env` không tự nạp vào một Uvicorn process đã chạy. Mặc định local không cần export credential. Khi dùng vendor, đặt credential/URL như mục 10 trước khi chạy Uvicorn.

Kiểm tra ở terminal khác:

```bash
curl http://127.0.0.1:8000/health
curl http://127.0.0.1:8000/metrics
```

`/metric` là alias của `/metrics`. Health chỉ báo API phản hồi; không kiểm tra vendor credential hoặc model đã tải xong. Lần đầu local OCR có thể cần tải cache và khởi tạo model trong từng worker. YuNet cần model tại `ai-engine/model-cache/face-detection/face_detection_yunet_2026may.onnx`; thiếu model chỉ làm face crop có status `model_not_available`, không tự tải model mặt trong hàm chuẩn hóa.

## 4. Build và chạy Laravel

Terminal thứ hai:

```bash
cd /home/user/document-image-preprocessing-system
npm run build
php artisan serve --host=127.0.0.1 --port=8001 --no-interaction
```

Mở địa chỉ tương ứng `APP_URL`. Khi sửa CSS/JS, có thể dùng terminal riêng chạy `npm run dev`. Chưa có queue job xử lý ảnh Laravel: request upload đợi AI Engine và lưu kết quả ngay, nên `queue:work` không phải thành phần cần thiết để thực hiện luồng upload này.

Để retention hoạt động, chạy scheduler trong terminal/process riêng:

```bash
php artisan schedule:work --no-interaction
```

Scheduler gọi `documents:purge-expired` hằng ngày lúc `DOCUMENT_RETENTION_PURGE_TIME` theo timezone ứng dụng. Lệnh xóa ảnh/JSON hết hạn, giữ bản ghi hồ sơ/audit; không đồng nghĩa xóa hàng khỏi database. Production cần cơ chế duy trì scheduler hoạt động.

## 5. Luồng sử dụng hiện có

1. Đăng nhập Customer; chọn **1–6 ảnh** JPG/JPEG/PNG/WEBP, tối đa **10 MiB mỗi ảnh**. Ảnh không có giới hạn dung lượng tối thiểu ở bước upload.
2. Nhấn **Xử lý hình ảnh và OCR**. Preview chỉ hiện ảnh đầu tiên, nhãn tệp liệt kê các ảnh đã chọn; server kiểm tra giới hạn số ảnh.
3. Laravel lưu ảnh gốc mã hóa và tạo hồ sơ từng ảnh; một ảnh gửi `/process`, nhiều ảnh gửi `/process-batch`.
4. FastAPI validate/quality gate rồi chỉnh chiều, crop, deskew, giảm chói, tăng sắc nét, resize/enhance; ảnh cuối có mỗi chiều 350–4096 px và 98 KiB–3 MiB. OCR/chuẩn hóa thực hiện sau tiền xử lý.
5. Laravel lưu từng kết quả thành công/thất bại. Nếu **đúng 2 ảnh và cả hai thành công**, gọi riêng đối chiếu CCCD; đảo thứ tự hai mặt vẫn được xét. Ba đến sáu ảnh không được tự tìm cặp.
6. Khi `matched`, trang hồ sơ chính hiển thị dữ liệu tổng hợp và cả hai mặt; khi không khớp/thiếu bằng chứng, hai hồ sơ vẫn riêng. Barcode decoded nhưng payload unsupported không đủ bằng chứng gộp.
7. Customer kiểm tra/sửa field được phép rồi gửi hồ sơ cho nhân viên. Chỉnh sửa không thay raw OCR và không tự chạy lại đối chiếu hai mặt.
8. Processor/Reviewer xem hàng đợi `pending_review`, chọn xác thực hoặc yêu cầu gửi lại kèm lý do. Trạng thái hồ sơ chính và mặt phụ được đồng bộ.
9. Admin xem dữ liệu, quản lý tài khoản, audit log, metrics và OCR provider; không upload/chỉnh OCR/xác thực trên UI hồ sơ.

Trang chủ hiện chỉ có tối đa **6 hồ sơ gần nhất** theo vai trò và ẩn các mặt phụ đã ghép. Chưa có trang lịch sử phân trang, tìm kiếm, xoay ảnh thủ công, retry thủ công hoặc export từ UI. Công cụ export Python là CLI riêng.

## 6. Dữ liệu, file tạm và mã hóa

| Dữ liệu | Vị trí / vòng đời |
| --- | --- |
| Ảnh gốc mã hóa | `storage/app/private/documents/originals/` |
| JPEG xử lý mã hóa | `storage/app/private/documents/processed/` |
| Face crop mã hóa | `storage/app/private/documents/faces/` |
| Raw/structured OCR | JSON trong bảng `image_documents`; chưa mã hóa ở tầng ứng dụng |
| Lựa chọn provider | `system_settings`, đồng bộ `storage/app/private/ocr-provider.json` |
| Workspace API | `ai-engine/tmp/<job-id>/input.<ext>` và `output/`; dọn khi kết thúc response |
| Debug CLI | `ai-engine/output/` mặc định; có thể ghi đè; có thể đổi qua `PIPELINE_OUTPUT_DIR` |
| Model cache | `ai-engine/model-cache/`, dùng lại qua các lần chạy |

File ảnh private có thể mang đuôi `.jpg/.png` nhưng nội dung là payload Crypt; xem qua route đã kiểm tra quyền để được giải mã. Với dữ liệu ảnh cũ, `documents:encrypt-legacy-storage --dry-run` chỉ đếm; bỏ cờ mới mã hóa. Không cần `storage:link` cho ảnh giấy tờ private.

## 7. SMTP và đặt lại mật khẩu

Cấu hình mail theo dịch vụ SMTP của bạn. `.env.example` có mẫu Gmail SMTP:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-16-character-app-password
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="Document Image Preprocessing System"
```

Sau khi sửa cấu hình Laravel, chạy `php artisan config:clear --no-interaction`. Link reset dùng `APP_URL` đúng scheme/host/cổng; token mặc định hết hạn sau 60 phút, throttle yêu cầu 60 giây. Dùng link mới nhất và đúng email. Đây là xác thực tài khoản; không phải xác thực danh tính bằng barcode/chip.

## 8. Kiểm tra và xử lý lỗi

Các lệnh kiểm tra khi cần (không phải thông báo rằng đã chạy và đạt trên mọi môi trường):

```bash
php artisan route:list --except-vendor --no-interaction
php artisan test --compact --no-interaction
```

`phpunit.xml` dùng SQLite in-memory, cache/session array và mail array. Cần `pdo_sqlite` kể cả khi ứng dụng local dùng MySQL. Chọn file/filter hẹp khi kiểm tra một thay đổi.

Với test logic Python, gọi các module unittest cụ thể, ví dụ từ `ai-engine/` sau khi activate venv:

```bash
python -m unittest test_barcode_service test_mrz_parser test_document_pairing
```

Không chạy discover mọi `test_*.py` một cách mặc định: các file như `test_orientation_rotate.py`, `test_document_crop.py`, `test_ocr.py` là script pipeline chạy thật và có thể tạo output/tải model. Chạy thủ công đầy đủ bằng `python pipeline.py input/ten-anh.jpg`; output CLI không phải lịch sử hồ sơ Laravel.

| Hiện tượng | Điểm kiểm tra |
| --- | --- |
| AI Engine không phản hồi | Uvicorn, `/health`, `AI_ENGINE_URL`, log Laravel; model readiness không được kiểm tra bởi health |
| Ảnh bị từ chối 422 | Mờ/thiếu tương phản, cháy sáng hoặc không thấy khung tối thiểu; response có mã quality gate |
| Batch có ảnh lỗi | Xem từng `documents[i]`, không chỉ top-level status; ảnh thành công không bị hủy |
| Hai ảnh không được gộp | Cần nhận diện một trước/một sau và số định danh khớp MRZ/barcode đã parse; không suy luận chỉ vì upload cùng nhau |
| PDF417 đọc được nhưng không có thông tin cá nhân | Kiểm tra `barcodes.records[].parsed`; payload nhị phân CCCD 2016 chưa có parser định danh |
| Chọn vendor nhưng OCR lỗi | Credential/endpoint trong môi trường Uvicorn; provider không tự fallback |
| Metrics chưa đổi trên Swagger | Gọi Execute lại; kiểm tra file state chia sẻ giữa Laravel và FastAPI |
| Upload nhiều ảnh bị 413 hoặc mất file | Mẫu Nginx chặn cả request ở 12 MiB; kiểm tra `post_max_size`, `upload_max_filesize`, `max_file_uploads` của PHP và proxy |
| Request hết thời gian khi OCR lâu | Timeout Laravel đơn/batch, subprocess tiền xử lý, PHP và Nginx phải tương thích; mẫu Nginx đặt 600 giây |
| Không có face crop | Model YuNet thiếu hoặc không phát hiện được mặt; không phải luôn có crop |
| Thiếu Vite manifest | Chạy `npm run build` hoặc dùng `npm run dev` |
| Database test báo could not find driver | Kiểm tra `pdo_sqlite` của PHP CLI |

Không đưa `.env`, API key, ảnh giấy tờ, private storage hoặc output/model cache lên repository công khai.

## 9. Các mẫu TLS/HTTPS trong repo

`nginx.document-image-preprocessing-system.conf.example` là mẫu production, không có nghĩa server đã được cấu hình. Mẫu dùng HTTP redirect HTTPS, TLS 1.2/1.3, HSTS, PHP-FPM 8.3 và timeout FastCGI 600 giây. Thay tên miền, đường dẫn project, certificate và socket theo máy triển khai. AI Engine tiếp tục bind nội bộ, không mở endpoint xử lý chưa có auth trực tiếp ra Internet.

Ví dụ cấu hình Laravel khi TLS đã được thiết lập:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://documents.example.com
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

```bash
php artisan optimize:clear --no-interaction
php artisan config:cache --no-interaction
```

`TRUSTED_PROXIES` chỉ khai báo các IP/CIDR proxy đáng tin theo hạ tầng. Mẫu local `nginx.local-mkcert.conf.example` nghe HTTPS cổng 8443 và proxy đến Laravel 8001, dùng certificate trong `storage/app/local-tls/`; cần tự chuẩn bị certificate và cấu hình trust/proxy tương ứng, không dùng certificate local cho production. Hai mẫu đều có giới hạn **12 MiB cho toàn request**; tăng giới hạn này và cấu hình PHP phù hợp nếu triển khai batch lớn hơn.

## 10. Chọn OCR provider và credential

Admin vào `/admin/ocr-vendor`, chọn một trong bốn provider rồi lưu. Lựa chọn áp dụng cho yêu cầu mới, có audit và đồng bộ file state; **không cần restart chỉ để đổi lựa chọn trong Admin**. Chưa có bảng `system_settings` thì chạy migration trước. Nếu đồng bộ file lỗi, cập nhật database rollback.

Các request Laravel gửi provider bằng header. FastAPI không nhận header thì đọc state file, sau đó fallback `OCR_PROVIDER`. Đặt `AI_OCR_PROVIDER_STATE_PATH` trong cả cấu hình Laravel và môi trường Uvicorn nếu dùng đường dẫn dùng chung khác; hai service phải nhìn thấy cùng nội dung file. Lựa chọn đã lưu có thể ghi đè giá trị mặc định `OCR_PROVIDER`.

### Local Hybrid

`local_hybrid` dùng PP-OCRv5 detector + VietOCR recognizer. Không yêu cầu API key vendor.

### FPT.AI Reader

Đặt các biến **trước khi khởi động Uvicorn**:

```bash
export FPT_AI_READER_API_KEY='api-key-cua-ban'
export FPT_AI_READER_DOCUMENT_TYPE=id_card
export FPT_AI_READER_TIMEOUT_SECONDS=30
# export FPT_AI_READER_URL='endpoint-ghi-de-neu-can'
```

Chọn `fpt_ai_reader` trong Admin, hoặc dùng `OCR_PROVIDER=fpt_ai_reader` khi chạy độc lập chưa có header/state override. `document_type` hỗ trợ `id_card` và `driver_license`; cấu hình này không tự đổi cho mỗi ảnh. Adapter dùng multipart `image`, header `api_key`; source mặc định URL CCCD/CMND `https://api.fpt.ai/vision/idr/vnm/`, GPLX `https://api.fpt.ai/vision/dlr/vnm`. Field trả về được chuyển thành dòng có polygon tổng hợp, không phải tọa độ thật trên ảnh.

### VNPT eKYC / External eKYC

```bash
export VNPT_EKYC_OCR_URL='https://your-gateway.example/ocr'
export VNPT_EKYC_OCR_API_KEY='credential-cua-ban'
export VNPT_EKYC_OCR_TIMEOUT_SECONDS=30
# Với external_ekyc, thay bằng EKYC_OCR_URL, EKYC_OCR_API_KEY,
# EKYC_OCR_TIMEOUT_SECONDS.
```

Hai adapter này gửi multipart `file`, Bearer token nếu có credential. Endpoint/gateway phải trả **schema raw OCR của ứng dụng**; code VNPT hiện không tự chuyển một response native bất kỳ của nhà cung cấp thành schema này. Chọn đúng provider trong Admin sau khi gateway đã được cấu hình.

Đổi API key/URL/timeout/document type trong môi trường cần restart Uvicorn. Lỗi vendor được trả về rõ ràng, không tự gửi ảnh lại cho provider khác. Luồng vendor ngoài gửi ảnh đã tiền xử lý ra endpoint đã cấu hình; barcode/MRZ/face crop vẫn xử lý local ở bước chuẩn hóa. Chi tiết hợp đồng: [OCR_MODEL_EVALUATION.md](OCR_MODEL_EVALUATION.md).
