# Hướng dẫn chạy hệ thống

Tài liệu này hướng dẫn chạy đầy đủ giao diện Laravel, MySQL và AI engine xử lý ảnh/OCR trên máy local.

## 1. Điều kiện cần có

- PHP 8.3 và Composer.
- MySQL đang cài trên máy.
- Node.js và npm (chỉ cần để build hoặc chạy Vite khi thay đổi giao diện).
- Python 3.12. Nên dùng bản này vì virtual environment hiện tại của AI engine dùng Python 3.12.

Toàn bộ lệnh dưới đây được chạy từ thư mục gốc project:

```bash
cd /home/user/document-image-preprocessing-system
```

## 2. Cài đặt Laravel và cấu hình database

Nếu mới lấy source về, cài package PHP và JavaScript:

```bash
composer install
npm install
```

Tạo file cấu hình nếu chưa có:

```bash
cp .env.example .env
php artisan key:generate
```

Mở `.env` và cấu hình MySQL phù hợp với máy local. Ví dụ:

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

Tạo database `document_image_preprocessing` trong MySQL nếu chưa có. Sau đó khởi động MySQL và chạy migration:

```bash
sudo systemctl start mysql
php artisan config:clear
php artisan migrate --no-interaction
```

Kiểm tra trạng thái migration:

```bash
php artisan migrate:status
```

> Nếu máy không dùng `systemctl`, hãy khởi động MySQL theo cách tương ứng với XAMPP, Docker hoặc công cụ quản lý MySQL đang dùng.

## 3. Cài và chạy AI engine

Mở một terminal riêng:

```bash
cd /home/user/document-image-preprocessing-system/ai-engine
```

Nếu thư mục `venv` đã có, kích hoạt nó:

```bash
source venv/bin/activate
```

Nếu chưa có virtual environment, tạo mới và cài các package đã chốt trong `requirements.txt`:

```bash
python3.12 -m venv venv
source venv/bin/activate
python -m pip install --upgrade pip
pip install -r requirements.txt
```

Chạy FastAPI server:

```bash
uvicorn app:app --host 127.0.0.1 --port 8000 --reload
```

Kiểm tra AI engine từ một terminal khác:

```bash
curl http://127.0.0.1:8000/health
```

Kết quả mong đợi có `"status":"ok"`. Khi xử lý lần đầu, PaddleOCR/VietOCR có thể tải model vào cache nên sẽ lâu hơn các lần sau.

## 4. Build giao diện và chạy Laravel

Mở terminal thứ hai tại thư mục gốc project.

Nếu chỉ cần chạy giao diện hiện tại, build asset một lần:

```bash
cd /home/user/document-image-preprocessing-system
npm run build
```

Sau đó khởi động Laravel:

```bash
php artisan serve --host=127.0.0.1 --port=8001
```

Mở giao diện tại:

```text
http://127.0.0.1:8001
```

Khi đang chỉnh sửa CSS/JavaScript/Blade và muốn asset tự cập nhật, chạy `npm run dev` ở terminal thứ ba thay cho việc build lại sau mỗi lần sửa:

```bash
npm run dev
```

## 5. Thứ tự chạy hằng ngày

Mỗi lần phát triển, mở ít nhất hai terminal:

**Terminal 1 — AI engine**

```bash
cd /home/user/document-image-preprocessing-system/ai-engine
source venv/bin/activate
uvicorn app:app --host 127.0.0.1 --port 8000 --reload
```

**Terminal 2 — Laravel**

```bash
cd /home/user/document-image-preprocessing-system
php artisan serve --host=127.0.0.1 --port=8001
```

Tùy chọn khi đang sửa giao diện, dùng **Terminal 3 — Vite**:

```bash
cd /home/user/document-image-preprocessing-system
npm run dev
```

MySQL phải đang chạy trước khi upload ảnh. Luồng thao tác trên UI là: chọn ảnh → nhấn **Xử lý hình ảnh và OCR** → Laravel gọi `POST /process` của AI engine → AI engine tiền xử lý, OCR và chuẩn hóa → Laravel lưu ảnh kết quả cùng JSON OCR vào MySQL → trang kết quả hiển thị toàn bộ dòng OCR và các trường chuẩn hóa.

## 6. Kiểm tra khi xảy ra lỗi

| Hiện tượng | Cách kiểm tra/khắc phục |
| --- | --- |
| UI báo không thể xử lý ảnh | Kiểm tra terminal AI engine còn chạy; mở `http://127.0.0.1:8000/health`; bảo đảm `.env` có `AI_ENGINE_URL=http://127.0.0.1:8000`; sau khi sửa `.env`, chạy `php artisan config:clear`. |
| Lỗi 404 `/process` | Dừng process Uvicorn cũ và chạy lại lệnh ở mục 3 để nạp `app.py` mới. |
| Lỗi kết nối MySQL | Khởi động MySQL, kiểm tra `DB_*` trong `.env`, rồi chạy `php artisan migrate --no-interaction`. |
| Lỗi thiếu cột OCR | Chạy migration ở mục 2. |
| Lỗi thiếu Vite manifest | Chạy `npm run build` hoặc để `npm run dev` hoạt động. |
| OCR lâu ở lần đầu | Chờ model được tải vào cache; các lần sau thường nhanh hơn. |

## 7. Dữ liệu được lưu ở đâu

- Ảnh gốc: `storage/app/private/documents/originals/`.
- Ảnh đã xử lý: `storage/app/private/documents/processed/`.
- Bản ghi ảnh và JSON OCR: bảng MySQL `image_documents`, hai cột `ocr_raw_data` và `ocr_structured_data`.
- Output kỹ thuật mới nhất của AI engine: `ai-engine/output/`. Các file tại đây bị ghi đè ở mỗi lần chạy, không phải lịch sử theo từng người dùng.

Vì ảnh giấy tờ và OCR có thể chứa dữ liệu cá nhân, không đưa các file trong `storage/`, `ai-engine/output/` hay `.env` lên repository công khai.
