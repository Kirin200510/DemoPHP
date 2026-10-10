# Document Image Preprocessing System

Hệ thống Laravel kết hợp FastAPI để xử lý ảnh giấy tờ, đọc OCR tiếng Việt và hỗ trợ quy trình khách hàng gửi hồ sơ để nhân viên xác thực.

## Chức năng hiện có

- Tiền xử lý ảnh: chỉnh chiều, xác định giấy tờ/crop phối cảnh, deskew, giảm chói, khử mờ và tăng chất lượng ảnh.
- OCR: `PP-OCRv5_server_det` phát hiện vùng chữ; `VietOCR vgg_transformer` nhận dạng tiếng Việt.
- Chuẩn hóa OCR cho CCCD, giấy phép lái xe và giấy chứng nhận đăng ký xe; giữ lại các dòng chưa gán trường.
- Crop khuôn mặt bằng YuNet khi model phát hiện được mặt.
- Đăng ký/đăng nhập/quên mật khẩu với Laravel Fortify; role/permission với Spatie Laravel Permission; Policy bảo vệ hồ sơ theo chủ sở hữu.
- Ba vai trò: `customer`, `processor`/`reviewer`, `admin`; có luồng gửi hồ sơ, xác thực hoặc yêu cầu gửi lại.

## Kiến trúc

```text
Browser
  -> Laravel (cổng 8001): xác thực, phân quyền, lưu private storage/MySQL
  -> FastAPI AI Engine (cổng 8000): xử lý ảnh + OCR + chuẩn hóa
  -> Laravel: lưu ảnh cuối, JSON OCR và trạng thái hồ sơ
```

Laravel gọi `POST /process` của AI Engine. Endpoint này trả ảnh cuối ở dạng base64, raw OCR, structured OCR và face crop (nếu có). Ảnh giấy tờ không được public trực tiếp: chúng nằm ở `storage/app/private/` và chỉ được trả qua route đã kiểm tra Policy.

## Tài liệu

- [Hướng dẫn chạy project](RUN_PROJECT.md)
- [Luồng xử lý AI Engine](AI_ENGINE_PROCESSING_FLOW.md)
- [Tích hợp Laravel và AI Engine](LARAVEL_AI_ENGINE_INTEGRATION.md)
- [Xác thực, phân quyền và nghiệp vụ hồ sơ](AUTHORIZATION_BUSINESS_RULES.md)
- [Đánh giá OCR model](OCR_MODEL_EVALUATION.md)

## Khởi động nhanh

```bash
# Terminal 1
cd ai-engine
source venv/bin/activate
uvicorn app:app --host 127.0.0.1 --port 8000 --reload

# Terminal 2
php artisan migrate --seed --no-interaction
npm run build
php artisan serve --host=127.0.0.1 --port=8001
```

Chi tiết cài đặt môi trường, MySQL, SMTP và tài khoản demo nằm trong [RUN_PROJECT.md](RUN_PROJECT.md).

## Bảo mật dữ liệu

Không commit `.env`, `storage/app/private/`, ảnh giấy tờ, `ai-engine/input/`, `ai-engine/output/`, `ai-engine/model-cache/` hoặc `ai-engine/venv/`. Confidence OCR chỉ là mức tham khảo; người dùng cần đối chiếu với ảnh gốc trước khi sử dụng dữ liệu.
