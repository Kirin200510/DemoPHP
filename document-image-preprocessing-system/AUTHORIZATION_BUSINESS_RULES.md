# Nghiệp vụ xác thực và phân quyền

Tài liệu này mô tả đúng cơ chế đang có trong project Laravel hiện tại. Xác thực tài khoản dùng **Laravel Fortify**, phân quyền dùng **Spatie Laravel Permission**, còn quyền trên từng hồ sơ được kiểm tra bằng **`ImageDocumentPolicy`** và middleware `auth`/`active`. Khu vực quản trị còn có middleware `role:admin`.

## 1. Vai trò hiện có

| Vai trò | Phạm vi chính |
| --- | --- |
| `customer` | Upload, xem và chỉnh sửa hồ sơ của chính mình; gửi hồ sơ chờ xác thực |
| `processor` | Xem hồ sơ đang `pending_review`, đối chiếu và xác thực hoặc yêu cầu gửi lại |
| `reviewer` | Có quyền xử lý hàng đợi giống `processor` (vai trò được seeder tạo sẵn) |
| `admin` | Policy cho phép đọc mọi hồ sơ theo ID, quản lý tài khoản/vai trò và audit log; không upload hoặc xử lý hồ sơ trên UI |

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

Trong luồng upload hiện tại, mỗi `ImageDocument` mới luôn được gán `user_id` là chủ hồ sơ. Policy không tin vào ID từ trình duyệt mà kiểm tra lại trên server. Cột `user_id` vẫn cho phép `NULL` ở database để tương thích dữ liệu cũ; hồ sơ không có chủ không thể được Customer thao tác qua Policy.

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

resubmission_required ──> pending_review (khách sửa OCR nếu cần rồi gửi lại cùng hồ sơ)

upload ảnh mới ──> tạo ImageDocument mới ở processing
```

- AI Engine tạo `completed` hoặc `failed`.
- Customer nhấn “Gửi hồ sơ cho nhân viên xác thực” để chuyển `completed` thành `pending_review`.
- Processor/Reviewer nhấn nút riêng “Xác thực hồ sơ” hoặc “Yêu cầu gửi lại”; yêu cầu gửi lại bắt buộc có lý do và được lưu ở `review_notes`.
- Customer xem trạng thái và lý do trên hồ sơ của mình, chỉnh dữ liệu chuẩn hóa rồi gửi lại. Upload ảnh mới luôn tạo hồ sơ mới; hồ sơ cũ không tự chuyển từ `resubmission_required` về `processing`.

## 5. Permission được seed và điểm kiểm tra quyền

Các permission được tạo trong `RolesAndPermissionsSeeder`, gồm nhóm hồ sơ (`documents.*`), tài khoản (`users.*`), vai trò (`roles.*`), quản lý quyền và `audit.view`. Route thực tế hiện dùng:

- `documents.create`, `documents.store`, `documents.show`.
- `documents.original`, `documents.processed`, `documents.face-crop`.
- `documents.ocr.update`, `documents.submit-review`, `documents.verify`, `documents.request-resubmission`.
- `admin.users`, `admin.users.access`, `admin.audit-logs`.

Tên trong danh sách trên là **route name**, không phải toàn bộ tên permission. Policy thực tế dùng các permission như `documents.upload`, `documents.view-own`, `documents.view-any`, `documents.edit-structured-ocr-own`, `documents.submit-review`, `documents.verify` và `documents.view-face-crop` kết hợp với owner/status/role. Cả thao tác xác thực và yêu cầu gửi lại hiện đi qua `review()`; policy này kiểm tra `documents.verify`, role `processor`/`reviewer` và trạng thái `pending_review`, chưa kiểm tra riêng `documents.request-resubmission`. Các route Admin dùng middleware `role:admin`.

Seeder cũng tạo một số permission dự phòng như `documents.view-raw-ocr`, `documents.edit-structured-ocr-any`, `documents.retry-processing`, `documents.archive`, `documents.delete`, `users.create` và `permissions.manage`, nhưng hiện chưa có controller/route/UI cho các thao tác đó. Chúng không tự cấp thêm chức năng. Hai JSON kỹ thuật cũng không có endpoint riêng: chúng chỉ được render trong trang chi tiết khi người xem có role `admin`.

## 6. Admin và audit log

Admin có thể mở trang quản lý tài khoản để gán một role trong danh sách seed và bật/tắt `is_active`. Không thể tự khóa chính tài khoản Admin đang đăng nhập. Policy cho phép Admin mở bất kỳ hồ sơ nào theo ID, nhưng trang danh sách trang chủ chỉ hiển thị tối đa 6 hồ sơ ở các trạng thái `completed`, `pending_review`, `resubmission_required` và `verified`; không liệt kê `processing` hoặc `failed`. Các lần upload, xử lý, xem ảnh, xem face crop, sửa OCR, gửi xác thực, xác thực, yêu cầu gửi lại và thay đổi quyền được ghi vào bảng `audit_logs`.

Ảnh và JSON được lưu trong private storage/MySQL, không phát public trực tiếp. Route ảnh luôn kiểm tra Policy trước khi trả file và đặt `Cache-Control: private, no-store`.
