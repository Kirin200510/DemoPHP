# Document Image Preprocessing System

Hệ thống Laravel + FastAPI để tiền xử lý ảnh giấy tờ, đọc OCR, chuẩn hóa dữ liệu và chuyển hồ sơ từ khách hàng sang nhân viên kiểm tra. Tài liệu được đối chiếu với source ngày **17/10/2026**; mô tả chức năng đã có trong code, không thay cho kết quả benchmark hay xác nhận triển khai production.

## Chức năng hiện có

- Tiền xử lý từng ảnh theo thứ tự: chỉnh chiều → crop/nắn phối cảnh → deskew → giảm chói → tăng độ sắc nét → resize/enhance.
- Upload 1–6 ảnh/lượt, mỗi ảnh JPG/JPEG/PNG/WEBP tối đa 10 MiB. Một ảnh đi qua `/process`; nhiều ảnh đi qua `/process-batch` và nhận kết quả độc lập theo thứ tự đầu vào.
- Quality gate từ chối sớm ảnh quá mờ/thiếu tương phản, cháy sáng nghiêm trọng hoặc không thấy khung giấy tờ tối thiểu. JPEG cuối có mỗi chiều 350–4096 px và dung lượng 98 KiB–3 MiB.
- OCR mặc định `local_hybrid`: PP-OCRv5 detector + VietOCR `vgg_transformer` chạy CPU. Có adapter `fpt_ai_reader`, `vnpt_ekyc` và `external_ekyc`.
- Admin chọn provider tại `/admin/ocr-vendor`. Lựa chọn lưu trong database và đồng bộ qua file state; yêu cầu Laravel gửi header `X-OCR-Provider`. Thay lựa chọn áp dụng cho yêu cầu sau mà không cần khởi động lại AI Engine. API key/endpoint vẫn đặt trong môi trường Uvicorn.
- Chuẩn hóa CCCD mẫu cũ/mới, giấy phép lái xe và giấy chứng nhận đăng ký xe; giữ dòng OCR, nguồn dữ liệu và các trường chưa nhận diện được. Có crop khuôn mặt bằng YuNet khi model và ảnh phù hợp.
- Phân tích MRZ TD1 và đọc barcode local bằng `zxing-cpp`. Payload không thuộc profile hỗ trợ được lưu nguyên để truy vết; chưa có bộ giải mã thông tin cá nhân từ payload nhị phân CCCD 2016.
- Khi upload **đúng 2 ảnh và cả hai xử lý thành công**, Laravel gọi riêng `/document-sides/match`. Chỉ gộp nếu nhận diện được một mặt trước/một mặt sau CCCD và bằng chứng định danh khớp; không mặc định hai ảnh là cùng thẻ.
- Customer xem/sửa OCR của mình và gửi duyệt; Processor/Reviewer xác thực hoặc yêu cầu gửi lại; Admin quản lý tài khoản, OCR provider, audit log và dashboard lỗi.
- Ảnh được mã hóa bằng Laravel Crypt trên private storage; OCR JSON nằm trong database. Retention mặc định 30 ngày xóa file/JSON khi scheduler chạy, giữ bản ghi hồ sơ và audit.
- Retry có giới hạn cho lỗi kết nối/5xx; batch chỉ thử lại các ảnh lỗi hệ thống. Lỗi chất lượng ảnh không retry.

## Kiến trúc và mức đồng thời

```text
Browser → Laravel: auth, Policy, lưu ảnh gốc, tạo hồ sơ processing
                → FastAPI /process hoặc /process-batch
                    → workspace tmp/<job-id>/ riêng cho mỗi ảnh
                    → sáu bước tiền xử lý tuần tự trong từng job
                    → OCR nội bộ qua process pool / OCR ngoài qua thread
                    → chuẩn hóa + MRZ + barcode + face crop
                ← JPEG/base64 + raw OCR + structured OCR
                → lưu kết quả completed/failed cho từng ảnh
                → nếu đủ 2 kết quả: gọi riêng /document-sides/match
                → hiển thị kết quả, gửi duyệt và nhân viên kiểm tra
```

Mỗi FastAPI process mặc định nhận tối đa **2 job nặng đồng thời** (`AI_MAX_CONCURRENT_JOBS`, giới hạn 1–4). `AI_LOCAL_OCR_WORKERS` mặc định bằng số job, không vượt giới hạn đó. Model OCR được tái sử dụng trong từng worker. Đây là xử lý đồng bộ qua HTTP có giới hạn đồng thời, chưa có hàng đợi xử lý hồ sơ chạy nền của Laravel.

Trang chủ chỉ hiển thị tối đa **6 hồ sơ gần nhất** theo phạm vi vai trò, ẩn các mặt phụ đã ghép. Trang lịch sử đầy đủ có phân trang mới là ý tưởng phát triển, **chưa có route/controller/view trong source hiện tại**.

## Tài liệu

- [Hướng dẫn cài đặt, cấu hình và chạy](RUN_PROJECT.md)
- [Luồng xử lý AI Engine, MRZ/barcode và ghép mặt](AI_ENGINE_PROCESSING_FLOW.md)
- [Hợp đồng Laravel ↔ FastAPI và lưu trữ](LARAVEL_AI_ENGINE_INTEGRATION.md)
- [Xác thực, phân quyền và nghiệp vụ hồ sơ](AUTHORIZATION_BUSINESS_RULES.md)
- [OCR model, provider và giới hạn đánh giá](OCR_MODEL_EVALUATION.md)

## Khởi động sau khi đã cài đặt/cấu hình

```bash
# Terminal 1, từ thư mục project
cd ai-engine
source venv/bin/activate
uvicorn app:app --host 127.0.0.1 --port 8000 --reload

# Terminal 2, từ thư mục project
php artisan migrate --seed --no-interaction
npm run build
php artisan serve --host=127.0.0.1 --port=8001
```

Cổng trên là cấu hình chạy local trong hướng dẫn. `.env.example` mặc định database SQLite; [RUN_PROJECT.md](RUN_PROJECT.md) có cả cách cấu hình SQLite và MySQL. Không chạy lại seeder trên dữ liệu thật để tránh cập nhật các tài khoản demo. Model lần đầu có thể cần tải về cache; `/health` chỉ kiểm tra API đang phản hồi, không kiểm tra vendor credential/model readiness.

## Công nghệ và dữ liệu riêng tư

Source PHP yêu cầu PHP 8.3 trở lên; bản cài được đối chiếu dùng Laravel 13.35.0, Fortify 1.41.0 và Spatie Laravel Permission 8.3.0. Frontend khai báo Vite 8/Tailwind CSS 4. AI Engine có venv Python 3.12, dependency trong `ai-engine/requirements.txt`.

Không commit `.env`, API key, ảnh giấy tờ, private storage, workspace hoặc model cache. Các file `.jpg` được mã hóa trong private storage cần đi qua controller để giải mã trước khi xem. Ghép hai mặt/MRZ/barcode và confidence OCR không xác thực tính thật của giấy tờ. Các mẫu Nginx hiện giới hạn **12 MiB cho cả request**; PHP/proxy phải được cấu hình phù hợp nếu upload nhiều ảnh.
