# Nghiệp vụ xác thực và phân quyền

Tài liệu này mô tả đúng cơ chế đang có trong project Laravel hiện tại. Xác thực tài khoản dùng **Laravel Fortify**, phân quyền dùng **Spatie Laravel Permission**, còn quyền trên từng hồ sơ được kiểm tra bằng **`ImageDocumentPolicy`** và middleware `auth`/`active`.

## 1. Vai trò hiện có

| Vai trò | Phạm vi chính |
| --- | --- |
| `customer` | Upload, xem và chỉnh sửa hồ sơ của chính mình; gửi hồ sơ chờ xác thực |
| `processor` | Xem hồ sơ đang `pending_review`, đối chiếu và xác thực hoặc yêu cầu gửi lại |
| `reviewer` | Có quyền xử lý hàng đợi giống `processor` (vai trò được seeder tạo sẵn) |
| `admin` | Đọc hồ sơ toàn hệ thống, quản lý tài khoản/vai trò và audit log; không upload hoặc xử lý hồ sơ trên UI |

Tài khoản mới đăng ký luôn được gán vai trò `customer` và bị đăng xuất sau khi đăng ký; người dùng phải đăng nhập lại.

## 2. Xác thực tài khoản

- Fortify cung cấp đăng ký, đăng nhập, đăng xuất, quên mật khẩu, đặt lại mật khẩu và xác nhận lại mật khẩu.
- `CreateNewUser` kiểm tra tên, email duy nhất và độ mạnh mật khẩu, sau đó gán role `customer`.
- `FortifyServiceProvider` chỉ cho đăng nhập tài khoản `is_active = true` và giới hạn đăng nhập 5 lần/phút theo email + IP.
- Middleware `active` tự đăng xuất tài khoản bị khóa.
- Link đặt lại mật khẩu dùng bảng `password_reset_tokens`, mặc định hết hạn sau 60 phút và giới hạn yêu cầu mới trong 60 giây. Link cũ bị vô hiệu khi yêu cầu link mới hoặc khi token đã dùng.
- SMTP và `APP_URL` phải trỏ đúng địa chỉ/cổng Laravel đang chạy; nếu dùng `127.0.0.1:8001` thì `APP_URL` cũng phải là `http://127.0.0.1:8001`.
- Các nút đăng nhập nhanh chỉ tồn tại trong môi trường `local`/`testing`, không được bật trên production.

## 3. Bảo vệ theo chủ sở hữu

Mỗi `ImageDocument` lưu `user_id` là chủ hồ sơ. Policy không tin vào ID từ trình duyệt mà kiểm tra lại trên server.

| Hành động | Customer | Processor/Reviewer | Admin |
| --- | --- | --- | --- |
| Upload ảnh | Hồ sơ của mình | Không | Không |
| Xem hồ sơ | Chỉ hồ sơ của mình | Chỉ `pending_review` | Tất cả trạng thái được lưu |
| Xem ảnh gốc/kết quả | Hồ sơ của mình | Hồ sơ đang chờ xác thực | Hồ sơ được phép xem |
| Xem ảnh khuôn mặt | Không | Có trên hồ sơ được phép xem | Có |
| Xem các trường OCR chuẩn hóa | Hồ sơ của mình | Hồ sơ đang chờ xác thực | Tất cả hồ sơ |
| Xem khối JSON OCR thô/JSON chuẩn hóa | Không | Không | Chỉ Admin trên giao diện |
| Sửa OCR chuẩn hóa | Chỉ hồ sơ mình ở `completed` hoặc `resubmission_required` | Không | Không trên UI hồ sơ |
| Gửi hồ sơ xác thực | Có, khi đã xử lý hoặc được yêu cầu gửi lại | Không | Không |
| Xác thực hồ sơ | Không | Có, chỉ `pending_review` | Không |
| Yêu cầu gửi lại + lý do | Không | Có, chỉ `pending_review` | Không |
| Quản lý tài khoản/vai trò | Không | Không | Có |
| Xem audit log | Không | Không | Có |

Khối “toàn bộ nội dung OCR đọc được” trên trang kết quả là danh sách dòng OCR để đối chiếu theo phạm vi hồ sơ; hai khối JSON kỹ thuật chỉ được render khi người dùng có role `admin`.

## 4. Luồng hồ sơ

```text
processing
  ├─ completed ──> pending_review ──> verified
  │                              └──> resubmission_required
  └─ failed

resubmission_required ──> processing (khách hàng upload lại)
```

- AI Engine tạo `completed` hoặc `failed`.
- Customer nhấn “Gửi hồ sơ cho nhân viên xác thực” để chuyển `completed` thành `pending_review`.
- Processor/Reviewer nhấn nút riêng “Xác thực hồ sơ” hoặc “Yêu cầu gửi lại”; yêu cầu gửi lại bắt buộc có lý do và được lưu ở `review_notes`.
- Customer xem trạng thái và lý do trên hồ sơ của mình, chỉnh dữ liệu chuẩn hóa rồi gửi lại.

## 5. Quyền được seed

Các permission được tạo trong `RolesAndPermissionsSeeder`, gồm nhóm hồ sơ (`documents.*`), tài khoản (`users.*`), vai trò (`roles.*`), quản lý quyền và `audit.view`. Route thực tế hiện dùng:

- `documents.create`, `documents.store`, `documents.show`.
- `documents.original`, `documents.processed`, `documents.face-crop`.
- `documents.ocr.update`, `documents.submit-review`, `documents.verify`, `documents.request-resubmission`.
- `admin.users`, `admin.users.access`, `admin.audit-logs`.

Các permission chưa có route tương ứng không tự tạo thêm khả năng trên UI; muốn mở rộng phải bổ sung đồng thời route, Policy, controller, giao diện và test.

## 6. Admin và audit log

Admin có thể mở trang quản lý tài khoản để gán một role trong danh sách seed và bật/tắt `is_active`. Không thể tự khóa chính tài khoản Admin đang đăng nhập. Các lần upload, xử lý, xem ảnh, xem face crop, sửa OCR, gửi xác thực, xác thực, yêu cầu gửi lại và thay đổi quyền được ghi vào bảng `audit_logs`.

Ảnh và JSON được lưu trong private storage/MySQL, không phát public trực tiếp. Route ảnh luôn kiểm tra Policy trước khi trả file và đặt `Cache-Control: private, no-store`.
