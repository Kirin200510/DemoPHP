# Hướng dẫn chạy project

Project gồm Laravel UI/API, MySQL và FastAPI AI Engine. Laravel chạy cổng `8001`, AI Engine chạy cổng `8000`.

## 1. Yêu cầu

- PHP 8.3, Composer và MySQL.
- Node.js/npm để build Vite.
- Python 3.12 cho AI Engine.
- Có thể dùng `systemctl`, XAMPP hoặc Docker để chạy MySQL.

```bash
cd /home/user/document-image-preprocessing-system
composer install
npm install
cp .env.example .env       # chỉ chạy nếu chưa có .env
php artisan key:generate   # chỉ chạy nếu APP_KEY còn trống
```

## 2. Cấu hình `.env`

Tạo database `document_image_preprocessing` rồi cấu hình tối thiểu:

```env
APP_URL=http://127.0.0.1:8001
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=document_image_preprocessing
DB_USERNAME=root
DB_PASSWORD=
AI_ENGINE_URL=http://127.0.0.1:8000
AI_ENGINE_CONNECT_TIMEOUT=5
AI_ENGINE_TIMEOUT=360
```

Sau đó chạy MySQL và migrate/seed:

```bash
sudo systemctl start mysql
php artisan config:clear
php artisan migrate --seed --no-interaction
```

`DatabaseSeeder` tạo role và tài khoản demo:

| Role | Email | Mật khẩu |
| --- | --- | --- |
| Admin | `admin@example.com` | `Admin@12345` |
| Processor | `processor@example.com` | `Processor@12345` |
| Customer | `test@example.com` | `password` |

Chỉ dùng các tài khoản này ở môi trường local; đổi mật khẩu trước khi triển khai thật. Tài khoản đăng ký mới được gán `customer` và phải đăng nhập lại sau khi đăng ký.

## 3. Chạy AI Engine

Mở terminal thứ nhất:

```bash
cd /home/user/document-image-preprocessing-system/ai-engine
python3.12 -m venv venv       # chỉ cần chạy lần đầu nếu chưa có venv
source venv/bin/activate
python -m pip install --upgrade pip
pip install -r requirements.txt
uvicorn app:app --host 127.0.0.1 --port 8000 --reload
```

Kiểm tra ở terminal khác:

```bash
curl http://127.0.0.1:8000/health
```

Kết quả đúng có `"status":"ok"`. Lần đầu OCR có thể chậm vì model được tải vào cache.

## 4. Build và chạy Laravel

Mở terminal thứ hai:

```bash
cd /home/user/document-image-preprocessing-system
npm run build
php artisan serve --host=127.0.0.1 --port=8001
```

Mở `http://127.0.0.1:8001`. Khi đang sửa CSS/JS, có thể dùng terminal thứ ba:

```bash
npm run dev
```

Nếu đã chạy Vite dev thì không cần build lại sau mỗi lần sửa frontend.

## 5. SMTP và đặt lại mật khẩu

Gmail cần bật xác minh hai bước và tạo App Password 16 ký tự. Không dùng mật khẩu Gmail thông thường và không commit `.env`.

```env
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-16-character-app-password
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="Document Image Preprocessing System"
```

Sau khi sửa `.env`:

```bash
php artisan config:clear
```

Yêu cầu reset mật khẩu một lần, sử dụng link mới nhất và email đúng với tài khoản. Token hết hạn sau 60 phút; yêu cầu mới bị giới hạn 60 giây.

## 6. Luồng sử dụng UI

1. Đăng nhập (hoặc đăng ký rồi đăng nhập lại).
2. Customer chọn ảnh và nhấn **Xử lý hình ảnh và OCR**.
3. Laravel gửi ảnh tới `POST /process` của AI Engine.
4. AI Engine trả ảnh cuối, OCR thô, OCR chuẩn hóa và face crop nếu phát hiện được.
5. Laravel lưu kết quả vào private storage/MySQL.
6. Customer kiểm tra/chỉnh trường OCR chuẩn hóa rồi gửi hồ sơ.
7. Processor mở hàng đợi `pending_review`, chọn **Xác thực hồ sơ** hoặc **Yêu cầu gửi lại** kèm lý do.
8. Admin chỉ tra cứu dữ liệu, quản lý tài khoản/vai trò và audit log; không xử lý giấy tờ trên UI.

## 7. Vị trí dữ liệu

- Ảnh gốc: `storage/app/private/documents/originals/`.
- Ảnh xử lý: `storage/app/private/documents/processed/`.
- Face crop: `storage/app/private/documents/faces/`.
- JSON OCR: cột `ocr_raw_data`, `ocr_structured_data` trong bảng `image_documents`.
- Output debug AI Engine: `ai-engine/output/` (dùng chung và có thể bị ghi đè mỗi lượt chạy).

## 8. Kiểm tra và xử lý lỗi

```bash
php artisan route:list --except-vendor
php artisan view:cache
npm run build
php artisan test --compact
```

Test Feature mặc định dùng SQLite in-memory. Máy chạy test phải có extension `pdo_sqlite`; nếu chỉ có `pdo_mysql`, test database sẽ báo `could not find driver`. Đây là thiếu extension môi trường, không phải lỗi của Policy/controller.

| Lỗi | Cách xử lý |
| --- | --- |
| UI báo AI engine không xử lý được | Kiểm tra Uvicorn, `/health`, `AI_ENGINE_URL` và log Laravel |
| Lỗi 404 `/process` | Khởi động lại Uvicorn từ thư mục `ai-engine` |
| Lỗi MySQL | Khởi động MySQL và kiểm tra các biến `DB_*` |
| Thiếu Vite manifest | Chạy `npm run build` hoặc `npm run dev` |
| Token reset không hợp lệ | Dùng link mới nhất, đúng email, đúng `APP_URL`, không dùng link đã dùng/hết hạn |

Không đưa `.env`, ảnh giấy tờ, private storage hoặc `ai-engine/output/` lên repository công khai.
