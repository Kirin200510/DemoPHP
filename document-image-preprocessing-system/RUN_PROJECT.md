# Hướng dẫn chạy project

Đối chiếu source ngày **21/10/2026**. Project gồm Laravel UI, database (SQLite hoặc MySQL) và FastAPI AI Engine. Các lệnh local dưới đây chọn Laravel cổng **8001**, AI Engine cổng **8000** để không trùng nhau; đây là ví dụ cấu hình, không phải phát hiện service đang chạy.

## 0. Chạy tự động trên Windows

Từ CMD trong thư mục project:

```bat
run_project.bat
```

### Công cụ cần cài trước

- Windows 10/11 x64, PowerShell 5.1 trở lên (có sẵn trên Windows).
- PHP 8.3+ CLI và Composer 2 trong PATH. Bật extension curl, dom, fileinfo, gd, iconv, mbstring, openssl, PDO, session, tokenizer, xml, xmlwriter, zip; thêm pdo_sqlite cho SQLite hoặc pdo_mysql cho MySQL. Composer kiểm tra thêm các yêu cầu của lock.
- Node.js thỏa engines của Vite 8: nhánh 20 từ 20.19, hoặc nhánh 22 từ 22.12 trở lên (không dùng Node 21).
- Python **3.12 x64**, có py launcher hoặc python trong PATH.
- Internet và dung lượng trống cho thư viện CPU/model; Visual C++ Redistributable x64 nếu Windows thiếu DLL runtime.

BAT không tự cài PHP/Node/Python/MySQL hay chỉnh php.ini của máy. Nó cài các **thư viện của project** sau khi các runtime trên đã sẵn sàng. Nếu dùng MySQL trong .env đã có, cần tạo database/khởi động MySQL trước; cài mới từ .env.example dùng SQLite nên không cần MySQL.

### Các bước bộ chạy thực hiện

1. Kiểm tra runtime, extension PHP và hai cổng 8001/8000. Dừng nếu cổng đang có tiến trình khác.
2. Tạo .env từ .env.example nếu chưa có, giữ file đang có. Từ chối APP_ENV=production.
3. composer install từ composer.lock, kiểm tra platform; npm ci khi có package-lock, sau đó build assets. Bỏ public/hot cũ để dùng assets vừa build.
4. Tạo venv Windows riêng ở ai-engine/.venv-windows, cài requirement.txt bằng pip và kiểm tra xung đột dependency. Không tái sử dụng venv Linux có sẵn.
5. Giữ APP_KEY hiện có, chỉ key:generate khi trống; tạo file SQLite nếu thiếu; migrate, không migrate:fresh. Chỉ db:seed demo khi users đang rỗng; database đã có user không tự chạy lại seeder hay reset mật khẩu/quyền.
6. Khởi chạy Uvicorn, php artisan serve và schedule:work trong cùng cửa sổ; chờ /health của AI và /up của Laravel phản hồi, mở trình duyệt. Giữ cửa sổ đang chạy; Ctrl+C kết thúc các tiến trình do script tạo.

Phần triển khai là run_project.ps1, BAT gọi PowerShell với ExecutionPolicy Bypass **chỉ cho tiến trình đó**, không đổi chính sách của máy.

```bat
rem Chay lai khi da cai dat, bo qua Composer/npm/pip/build
run_project.bat -SkipInstall

rem Doi cong, khong tu mo trinh duyet
run_project.bat -WebPort 8081 -AiPort 8080 -NoBrowser
```

Bộ chạy đặt APP_URL/AI_ENGINE_URL theo cổng truyền vào, tắt APP_FORCE_HTTPS và SESSION_SECURE_COOKIE **trong môi trường tiến trình local**, không sửa các giá trị này trong .env. Nó xóa config cache để các giá trị mới có hiệu lực. Bộ chạy không thiết lập Nginx/chứng chỉ HTTPS 8443. Dùng cấu hình HTTPS thủ công ở mục 9 khi cần.

Riêng khi gọi bằng BAT, các biến OCR_PROVIDER, AI_OCR_PROVIDER_STATE_PATH, AI_MAX_CONCURRENT_JOBS, AI_LOCAL_OCR_WORKERS, FPT_AI_READER_*, VNPT_EKYC_OCR_* và EKYC_OCR_* được đọc từ .env sang tiến trình AI nếu chưa được đặt trong môi trường. Hỗ trợ dòng KEY=value đơn giản, giá trị có thể bọc dấu nháy; không triển khai đầy đủ nội suy/định dạng multiline của dotenv. Khi chạy Uvicorn thủ công, vẫn phải tự export/set biến như các mục bên dưới. Không in credential ra màn hình.

Model OCR được tải theo cơ chế lazy ở lần sử dụng đầu tiên; khởi động thành công không chứng minh đã OCR thành công. YuNet là tùy chọn: cần file model tại đúng đường dẫn source; thiếu model thì face_crop báo model_not_available, không làm OCR thất bại. SMTP mẫu cần thay credential để gửi mail reset thật.

Dependency Python được ràng buộc cho môi trường CPU mới (NumPy 1.26.4; OpenCV cùng bản 4.10.0.84; torch/torchvision CPU), khác bản freeze Linux cũ. Không tự thay thư viện trong venv đang vận hành. Không có kết quả chạy bộ cài Windows trong môi trường Linux dùng để cập nhật source/tài liệu này.

### File requirements

| File | Cách sử dụng |
| --- | --- |
| requirement.txt | python -m pip install -r requirement.txt từ root |
| ai-engine/requirements.txt | Danh sách Python chung, root requirement.txt tham chiếu file này |
| ai-engine/requirements-linux.lock.txt | Bộ 110 package runtime đã ghim cho launcher Linux x86_64/Python 3.12 |
| requirement-php.txt / php-requirements.txt | Danh sách PHP/extension/package tham khảo đồng bộ từ composer.lock, không phải file pip |
| composer.lock | composer install để cài đúng PHP dependency, gồm laravel-dompdf |
| package-lock.json | npm ci để cài JS dependency |

Bản Python cài mới dùng ONNX Runtime cho PaddleX/PaddleOCR theo source, không yêu cầu cài PaddlePaddle hoặc CUDA. VietOCR dùng PyTorch CPU. Các gói OpenCV có nhiều tên do dependency upstream yêu cầu; được ghim cùng phiên bản để tránh trộn nhị phân cv2. Danh sách chỉ ghim gói chính/constraint cần thiết, các gói transitive còn do pip giải nên chưa tương đương một lock đầy đủ.

## 0.1. Chạy tự động trên Linux

Bộ chạy `run_project.sh` dành cho Linux x86_64 dùng glibc >=2.28, Bash và Python 3.12 x64. Không dùng bộ dependency này cho Alpine/musl hoặc ARM. Cài trước Python 3.12, Node.js thỏa Vite 8 (20.19 hoặc 22.12 trở lên theo điều kiện ở trên), PHP 8.3+ và Composer 2, cùng extension PHP. Không gọi cả script bằng sudo.

```bash
chmod +x run_project.sh
./run_project.sh
```

Nếu dùng Ubuntu 24.04 hoặc bản dẫn xuất Ubuntu noble (như Mint 22), tùy chọn dưới đây cài PHP 8.3/extension, Composer, python3.12-venv, curl/git/unzip, libGL, GLib và libgomp bằng sudo apt; có thể yêu cầu mật khẩu Linux. Python 3.12 để chạy bộ cài và Node đúng phiên bản vẫn cần có trước:

```bash
./run_project.sh --install-system-deps
```

Tùy chọn cài package hệ điều hành hỗ trợ Ubuntu 24.04 và các bản dẫn xuất dùng Ubuntu noble như Mint 22; distro khác cần tự cài các package tương đương. Mặc định script không gọi sudo/apt. Node từ kho mặc định Ubuntu có thể quá cũ cho Vite 8, nên script không tự cài hoặc nâng Node qua apt.

Quy trình cài dependency, giữ key/database, chỉ seed khi users rỗng và override URL local tương tự bộ chạy Windows. Python được cài trong `ai-engine/.venv-linux` riêng từ `ai-engine/requirements-linux.lock.txt` (110 package runtime đã ghim), không dùng hoặc cập nhật `ai-engine/venv` đang có. Setuptools 80.10.2 được giữ để gdown/VietOCR còn import được pkg_resources. Composer/npm/pip và bước build chỉ bị bỏ qua khi truyền --skip-install. Mỗi lần chạy vẫn migrate và kiểm tra database. MySQL đang cấu hình phải được khởi động/tạo database trước; cài mới dùng SQLite từ .env.example.

```bash
./run_project.sh --skip-install
./run_project.sh --web-port 8081 --ai-port 8080 --no-browser
```

Laravel/AI mặc định cổng 8001/8000, chỉ bind loopback; scheduler chạy cùng launcher. File .run-project.lock ngăn chạy hai launcher Linux trong cùng checkout. Nếu cổng đang dùng, script dừng trước khi cài dependencies. Script từ chối môi trường production và không thiết lập Nginx/HTTPS.

Log được append riêng cho từng dịch vụ:

```bash
tail -f storage/logs/run-project-ai.log
tail -f storage/logs/run-project-laravel.log
tail -f storage/logs/run-project-scheduler.log
```

Chờ AI /health và Laravel /up thành công rồi mở trình duyệt nếu có desktop và xdg-open. Model OCR tải ở lần dùng đầu; YuNet tùy chọn như mục Windows. Ctrl+C/SIGTERM dừng các process group do launcher tạo, gồm process con; nếu một dịch vụ thoát thì các dịch vụ còn lại cũng được dừng. Nội dung .env được đọc dưới dạng KEY=value đơn giản, không source/eval như shell; các biến OCR được chuyển sang môi trường AI tương tự Windows, không in credential.

### Kết quả kiểm chứng ngày 21/10/2026

Đã chạy trên Linux Mint 22.3 x86_64 (base Ubuntu noble), PHP 8.3.6, Node 22.22.2, Python 3.12.3. Bản sao tại /tmp không có vendor/node_modules/venv/model-cache/database/.env lúc bắt đầu; dùng SQLite và cổng riêng, không dùng dữ liệu hoặc sửa thư viện của hệ thống đang chạy. Hai extension PHP gd/zip còn thiếu được giải nén vào /tmp rồi nạp qua PHP_INI_SCAN_DIR, không cài vào PHP của máy.

| Kiểm tra | Kết quả |
| --- | --- |
| Composer install + check-platform-reqs | Thành công |
| npm ci + Vite build | Thành công |
| pip install mới + pip check | Thành công sau khi ghim setuptools 80.10.2 |
| Migrate và seed trên SQLite trống | Thành công sau khi reset cache quyền trong seeder; regression test DatabaseBootstrapTest qua 11 assertions |
| AI /health và Laravel /up | HTTP 200, scheduler chạy |
| Xử lý ảnh qua AI với model-cache mới | HTTP 200, completed, có JPEG và 16 dòng OCR trên ảnh kiểm thử |
| Customer đăng nhập và upload qua Laravel | Thành công, trang hồ sơ completed |
| Tải PDF; Admin xuất CSV với hai ngày trống | HTTP 200, đúng định dạng file |
| Dừng bằng SIGTERM/Ctrl+C | Exit 0; hai cổng web/AI được giải phóng, không còn dịch vụ kiểm thử lắng nghe |
| Cài/chạy lại đầy đủ | Giữ nguyên .env/APP_KEY, mật khẩu demo và hồ sơ đã tạo |
| 7 kiểm tra bảo vệ launcher | Qua: help, cổng trùng/không hợp lệ/bị chiếm, production, thiếu dependency, không thực thi giá trị .env và giữ key |

Đã phát hiện và sửa hai lỗi cài mới: setuptools mới bỏ pkg_resources trong khi gdown còn cần; DatabaseSeeder dùng WithoutModelEvents nên cache permissions cần được làm mới trước khi gán role. Bộ phiên bản runtime kiểm chứng được ghi vào requirements-linux.lock.txt để máy khác không tự lấy phiên bản transitive mới hơn.

Đây là kiểm thử bản sao sạch trên máy Linux hiện tại, không phải xác nhận đã chạy trên mọi thiết bị/distro. Chưa chạy sudo apt install thật: chỉ tải extension tạm và kiểm tra apt mô phỏng giải được danh sách package. Máy đích vẫn cần runtime, kiến trúc/glibc phù hợp, Internet tải package/model và RAM/dung lượng cho OCR. MySQL, SMTP thật, HTTPS/Nginx và các vendor OCR ngoài không nằm trong lượt kiểm chứng này.

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

Trang chủ có tối đa **6 hồ sơ gần nhất** theo vai trò, ẩn mặt phụ đã ghép. Customer mở `/my-documents` để xem lịch sử 10 hồ sơ/trang và lọc trạng thái. Có PDF theo hồ sơ và CSV Admin; chưa có tìm kiếm tự do, xoay ảnh hoặc retry thủ công. Công cụ export Python vẫn là CLI riêng.

### Xuất báo cáo và điều kiện lọc

- Customer mở hồ sơ chính đủ quyền, chưa purge, trạng thái completed/pending_review/verified/resubmission_required và có normalized value để tải PDF từ nút Xuất báo cáo PDF. Staff/Admin không được xuất PDF theo policy hiện tại.
- Admin vào `/admin/exports`, chọn management hoặc ocr. Cần documents.export-management-csv; OCR cần thêm documents.export-ocr-csv. Giới hạn POST 5/phút và tối đa 5.000 hồ sơ chính.
- Lọc status, document_type và ngày tạo created_at. Bỏ trống hai ngày để không lọc thời gian. Nhập Từ ngày thì phải nhập Đến ngày >= Từ ngày; chỉ nhập Đến ngày được phép. So sánh bao gồm ngày đầu/ngày cuối theo timezone config ứng dụng (hiện UTC).
- OCR chỉ lấy hồ sơ completed/pending_review/verified/resubmission_required chưa purge, có normalized field không rỗng; dữ liệu ghép được ưu tiên. CSV không có kết quả vẫn có header.
- Giới hạn source hiện tại: loại CCCD chưa tách new/legacy theo card_version, Unknown/unknown chưa thống nhất nhãn; form CSV thiếu @csrf và các select chưa giữ old(). Đây là các điểm đã rà soát, không được tài liệu coi là đã sửa. Chi tiết ở LARAVEL_AI_ENGINE_INTEGRATION.md.
- Khi OCR thiếu field, Customer được bổ sung trường hợp lệ của CCCD, bằng lái hoặc đăng ký xe rồi lưu; field lạ bị từ chối thay vì âm thầm bỏ qua.

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
