# Document Image Preprocessing System

Hệ thống Laravel + FastAPI để tiền xử lý ảnh giấy tờ, đọc OCR, chuẩn hóa dữ liệu và chuyển hồ sơ từ khách hàng sang nhân viên kiểm tra. Tài liệu được đối chiếu với source ngày **21/10/2026**; mô tả chức năng đã có trong code, không thay cho kết quả benchmark hay xác nhận triển khai production.

## Chức năng hiện có

- Tiền xử lý từng ảnh theo thứ tự: chỉnh chiều → crop/nắn phối cảnh → deskew → giảm chói → tăng độ sắc nét → resize/enhance.
- Upload 1–6 ảnh/lượt, mỗi ảnh JPG/JPEG/PNG/WEBP tối đa 10 MiB. Một ảnh đi qua `/process`; nhiều ảnh đi qua `/process-batch` và nhận kết quả độc lập theo thứ tự đầu vào.
- Quality gate từ chối sớm ảnh quá mờ/thiếu tương phản, cháy sáng nghiêm trọng hoặc không thấy khung giấy tờ tối thiểu. JPEG cuối có mỗi chiều 350–4096 px và dung lượng 98 KiB–3 MiB.
- OCR mặc định `local_hybrid`: PP-OCRv5 detector + VietOCR `vgg_transformer` chạy CPU. Có adapter `fpt_ai_reader`, `vnpt_ekyc` và `external_ekyc`.
- Admin chọn provider tại `/admin/ocr-vendor`. Lựa chọn lưu trong database và đồng bộ qua file state; yêu cầu Laravel gửi header `X-OCR-Provider`. Thay lựa chọn áp dụng cho yêu cầu sau mà không cần khởi động lại AI Engine. API key/endpoint vẫn đặt trong môi trường Uvicorn.
- Chuẩn hóa CCCD mẫu cũ/mới, giấy phép lái xe và giấy chứng nhận đăng ký xe; giữ dòng OCR, nguồn dữ liệu và các trường chưa nhận diện được. Có crop khuôn mặt bằng YuNet khi model và ảnh phù hợp.
- Phân tích MRZ TD1 và đọc barcode local bằng `zxing-cpp`. Payload không thuộc profile hỗ trợ được lưu nguyên để truy vết; chưa có bộ giải mã thông tin cá nhân từ payload nhị phân CCCD 2016.
- Khi upload **đúng 2 ảnh và cả hai xử lý thành công**, Laravel gọi riêng `/document-sides/match`. Chỉ gộp nếu nhận diện được một mặt trước/một mặt sau CCCD và bằng chứng định danh khớp; không mặc định hai ảnh là cùng thẻ.
- Customer có lịch sử phân trang, xuất PDF hồ sơ đủ điều kiện; Admin xuất CSV quản lý hoặc OCR với bộ lọc trạng thái, loại giấy tờ và ngày tạo.
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

Trang chủ hiển thị tối đa **6 hồ sơ gần nhất** theo vai trò và ẩn mặt phụ đã ghép. Customer có trang `/my-documents`: 10 hồ sơ/trang, lọc theo trạng thái, giữ bộ lọc khi chuyển trang. Trang này chỉ lấy hồ sơ thuộc tài khoản đang đăng nhập.

## Tài liệu

- [Hướng dẫn cài đặt, cấu hình và chạy](RUN_PROJECT.md)
- [Luồng xử lý AI Engine, MRZ/barcode và ghép mặt](AI_ENGINE_PROCESSING_FLOW.md)
- [Hợp đồng Laravel ↔ FastAPI và lưu trữ](LARAVEL_AI_ENGINE_INTEGRATION.md)
- [Xác thực, phân quyền và nghiệp vụ hồ sơ](AUTHORIZATION_BUSINESS_RULES.md)
- [OCR model, provider và giới hạn đánh giá](OCR_MODEL_EVALUATION.md)

## Cài và chạy trên Windows

Cài trước PHP 8.3+ cùng extension, Composer 2, Node.js 20.19 hoặc 22.12 trở lên theo điều kiện Vite 8, Python **3.12 x64**; đưa các công cụ vào PATH. Sau đó mở CMD tại thư mục project:

```bat
run_project.bat
```

Bộ chạy cài Composer từ lock, npm từ lock, Python CPU trong `ai-engine/.venv-windows`, build frontend, tạo `.env` nếu thiếu, giữ key cũ, migrate và seed demo chỉ khi chưa có user. Sau đó chạy Laravel cổng 8001, AI Engine cổng 8000 và scheduler. Cần Internet để tải package/model. MySQL đã cấu hình trong `.env` phải được tạo và khởi động trước; cài mới mặc định dùng SQLite.

```bat
run_project.bat -SkipInstall
run_project.bat -WebPort 8081 -AiPort 8080 -NoBrowser
```

`run_project.ps1` là phần triển khai được BAT gọi. Bộ chạy dành cho local HTTP; HTTPS 8443/Nginx là cấu hình riêng. Hướng dẫn và giới hạn: [RUN_PROJECT.md](RUN_PROJECT.md).

## Cài và chạy trên Linux

Cần Bash, Python **3.12 x64**, PHP 8.3+, Composer 2 và Node.js phù hợp Vite 8. Chạy bằng tài khoản thường:

```bash
chmod +x run_project.sh
./run_project.sh
```

Trên Ubuntu 24.04 hoặc bản dựa trên Ubuntu noble (như Mint 22), có thể thêm `--install-system-deps` để cài PHP/Composer, venv và các thư viện hệ thống bằng sudo apt. Python 3.12 và Node.js đúng phiên bản cần có trước. Script dùng `ai-engine/.venv-linux`, không sửa venv cũ. Các lựa chọn: `--skip-install`, `--web-port 8081 --ai-port 8080`, `--no-browser`. Log dịch vụ ở `storage/logs/run-project-*.log`; Ctrl+C dừng các dịch vụ do script tạo. Chi tiết ở [RUN_PROJECT.md](RUN_PROJECT.md).

## Danh sách dependency

- `requirement.txt`: đầu vào pip ở thư mục gốc, tham chiếu `ai-engine/requirements.txt`.
- `ai-engine/requirements-linux.lock.txt`: 110 package runtime đã ghim từ bản cài Linux x86_64/Python 3.12 qua kiểm thử; launcher Linux dùng file này.
- `ai-engine/requirements.txt`: dependency CPU cho Python 3.12; bỏ các gói CUDA/Linux khỏi danh sách cài chung. NumPy/OpenCV được ràng buộc tương thích; đây không phải snapshot đầy đủ mọi gói transitive.
- `requirement-php.txt` và `php-requirements.txt`: danh sách tham khảo PHP/extension/package lấy từ `composer.lock`; cài bằng `composer install`, không dùng pip.
- `composer.json`/`composer.lock`, `package.json`/`package-lock.json`: nguồn cài PHP/JS thực tế.

## Công nghệ và dữ liệu riêng tư

Source PHP yêu cầu PHP 8.3 trở lên; bản cài được đối chiếu dùng Laravel 13.35.0, Fortify 1.41.0 và Spatie Laravel Permission 8.3.0. Frontend khai báo Vite 8/Tailwind CSS 4. AI Engine có venv Python 3.12, dependency trong `ai-engine/requirements.txt`.

Không commit `.env`, API key, ảnh giấy tờ, private storage, workspace hoặc model cache. Các file `.jpg` được mã hóa trong private storage cần đi qua controller để giải mã trước khi xem. Ghép hai mặt/MRZ/barcode và confidence OCR không xác thực tính thật của giấy tờ. Các mẫu Nginx hiện giới hạn **12 MiB cho cả request**; PHP/proxy phải được cấu hình phù hợp nếu upload nhiều ảnh.
