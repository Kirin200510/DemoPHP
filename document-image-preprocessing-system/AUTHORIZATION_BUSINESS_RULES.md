# Nghiệp vụ xác thực và phân quyền

Đối chiếu source ngày **21/10/2026**. Tài liệu mô tả cơ chế đang có trong project Laravel hiện tại. Xác thực tài khoản dùng **Laravel Fortify**, phân quyền dùng **Spatie Laravel Permission**, còn quyền trên từng hồ sơ được kiểm tra bằng **`ImageDocumentPolicy`** và middleware `auth`/`active`. Khu vực quản trị còn có middleware `role:admin`.

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
- `CreateNewUser` kiểm tra tên, email duy nhất và mật khẩu theo `Password::default()` kèm xác nhận mật khẩu, sau đó gán role `customer`. Source chưa cấu hình chính sách độ mạnh riêng ngoài quy tắc mặc định này.
- `FortifyServiceProvider` chỉ cho đăng nhập tài khoản `is_active = true` và giới hạn đăng nhập 5 lần/phút theo email + IP.
- Middleware `active` tự đăng xuất tài khoản bị khóa.
- Link đặt lại mật khẩu dùng bảng `password_reset_tokens`, mặc định hết hạn sau 60 phút và giới hạn yêu cầu mới trong 60 giây. Link cũ bị vô hiệu khi yêu cầu link mới hoặc khi token đã dùng.
- SMTP và `APP_URL` phải trỏ đúng địa chỉ/cổng Laravel đang chạy; nếu dùng `127.0.0.1:8001` thì `APP_URL` cũng phải là `http://127.0.0.1:8001`.
- Controller đăng nhập nhanh chỉ hoạt động `local`/`testing`, ngoài hai môi trường này trả 404; chỉ có ba tài khoản demo admin/processor/customer.
- Features email verification, two-factor và passkeys chưa được bật trong `config/fortify.php`; việc có limiter/cột dữ liệu không đồng nghĩa có workflow UI tương ứng.

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
| Lịch sử `/my-documents` | Hồ sơ của mình, 10/trang | Không theo seed hiện tại | Không theo seed hiện tại |
| Xuất PDF | Hồ sơ của mình đủ điều kiện | Không | Không |
| Xuất CSV quản lý/OCR | Không | Không | Có, cần permission tương ứng |
| Xem dashboard lỗi / đổi OCR provider | Không | Không | Có |

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

- Laravel tạo `processing` khi lưu ảnh gốc; sau khi nhận kết quả AI Engine/lưu trữ, controller đặt `completed` hoặc `failed` cho từng hồ sơ.
- Customer nhấn “Gửi hồ sơ cho nhân viên xác thực” để chuyển `completed` thành `pending_review`.
- Processor/Reviewer nhấn nút riêng “Xác thực hồ sơ” hoặc “Yêu cầu gửi lại”; yêu cầu gửi lại bắt buộc có lý do và được lưu ở `review_notes`.
- Customer xem trạng thái và lý do trên hồ sơ của mình, chỉnh dữ liệu chuẩn hóa rồi gửi lại. Upload ảnh mới luôn tạo hồ sơ mới; hồ sơ cũ không tự chuyển từ `resubmission_required` về `processing`.

### Nhiều ảnh và hồ sơ ghép hai mặt

- Upload nhận 1–6 ảnh, tạo một `ImageDocument` cho mỗi ảnh. Xử lý ảnh độc lập; chỉ khi đúng 2 ảnh đều thành công mới gọi bước đối chiếu CCCD riêng.
- Nếu `matched`, bản ghi mặt trước là hồ sơ chính; mặt sau có `merged_into_document_id` trỏ về mặt trước. Giữ raw OCR/ảnh của từng mặt; lưu dữ liệu ghép trên hồ sơ chính. Kết quả chưa khớp/thiếu bằng chứng vẫn giữ hai hồ sơ riêng.
- Trang chủ luôn loại mặt phụ khỏi danh sách. Mở route chi tiết mặt phụ vẫn qua Policy rồi mới redirect sang hồ sơ chính. Thao tác sửa/gửi duyệt/xác thực/yêu cầu gửi lại chỉ cho hồ sơ chính; các thay đổi trạng thái review được đồng bộ sang mặt phụ.
- Sửa OCR nhận chuỗi nullable tối đa 2000 ký tự mỗi field, cập nhật `normalized_value` và dấu vết `manually_edited`, `edited_by`, `edited_at`. CCCD, giấy phép lái xe và đăng ký xe có danh sách field được bổ sung thủ công trong `DocumentController::MANUALLY_ADDABLE_FIELDS`, kể cả khi OCR chưa tạo field. Field đã tồn tại dạng object cũng được cập nhật; field lạ không hợp lệ gây validation error thay vì bị bỏ qua và báo thành công. Không tự kiểm định ngày/số định danh ở bước sửa và không tự chạy lại bước ghép mặt sau chỉnh sửa. Raw OCR từng ảnh được giữ nguyên.
- Barcode chỉ `decoded` nhưng `parsed.status=unsupported` không đủ bằng chứng gộp hồ sơ. Kết quả ghép mặt và nút xác thực của nhân viên là hai bước nghiệp vụ khác nhau; engine không xác thực giấy tờ thật.

## 5. Permission được seed và điểm kiểm tra quyền

Các permission được tạo trong `RolesAndPermissionsSeeder`, gồm nhóm hồ sơ (`documents.*`), tài khoản (`users.*`), vai trò (`roles.*`), quản lý quyền và `audit.view`. Route thực tế hiện dùng:

- `documents.create`, `documents.store`, `documents.show`.
- `documents.original`, `documents.processed`, `documents.face-crop`.
- `documents.ocr.update`, `documents.submit-review`, `documents.verify`, `documents.request-resubmission`.
- `admin.users`, `admin.users.access`, `admin.audit-logs`.
- `admin.processing-metrics`, `admin.ocr-vendor`, `admin.ocr-vendor.update`.

Tên trong danh sách trên là **route name**, không phải toàn bộ tên permission. Policy thực tế dùng các permission như `documents.upload`, `documents.view-own`, `documents.view-any`, `documents.edit-structured-ocr-own`, `documents.submit-review`, `documents.verify` và `documents.view-face-crop` kết hợp với owner/status/role. Cả thao tác xác thực và yêu cầu gửi lại hiện đi qua `review()`; policy này kiểm tra `documents.verify`, role `processor`/`reviewer` và trạng thái `pending_review`, chưa kiểm tra riêng `documents.request-resubmission`. Các route Admin dùng middleware `role:admin`.

Seeder cũng tạo các permission như `documents.edit-structured-ocr-any`, `documents.retry-processing`, `documents.archive`, `documents.delete`, `users.create` và `permissions.manage`, nhưng hiện chưa có controller/route/UI cho các thao tác tương ứng. Chúng không tự cấp thêm chức năng. `documents.view-raw-ocr` có trong seed nhưng hai JSON kỹ thuật hiện được render theo role `admin`, không kiểm tra permission này và không có endpoint JSON riêng. `viewAny()` của Policy hiện chỉ cho staff; chưa có route danh sách toàn bộ hồ sơ sử dụng method đó.

## 6. Admin và audit log

Admin có thể mở trang quản lý tài khoản để gán một role trong danh sách seed và bật/tắt `is_active`. Không thể tự khóa chính tài khoản Admin đang đăng nhập. Policy cho phép Admin mở bất kỳ hồ sơ nào theo ID, nhưng trang danh sách trang chủ chỉ hiển thị tối đa 6 hồ sơ ở các trạng thái `completed`, `pending_review`, `resubmission_required` và `verified`; không liệt kê `processing` hoặc `failed`. Customer thấy tối đa 6 hồ sơ của mình ở sáu trạng thái đã khai báo; Processor/Reviewer chỉ thấy tối đa 6 hồ sơ đang `pending_review`. Customer có `/my-documents` riêng: 10 hồ sơ/trang, lọc sáu trạng thái hoặc `all`, chỉ hồ sơ của mình và loại mặt phụ; trạng thái không hợp lệ trả 404. Bộ lọc này không nằm trong danh sách sáu hồ sơ ở trang chủ. Trang user Admin có phân trang 20 tài khoản, audit log phân trang 30 sự kiện. Admin còn có trang chỉ-đọc `/admin/processing-metrics`, tổng hợp **sự kiện audit xử lý** trong cửa sổ cảnh báo, không đếm trực tiếp status hồ sơ. Sự kiện thành công vẫn được tính sau khi hồ sơ chuyển sang chờ duyệt/đã duyệt. Ảnh bị từ chối (`invalid_image`) và lỗi chưa phân loại được đếm riêng, không đưa vào mẫu số tỷ lệ lỗi hệ thống; trang hiển thị tối đa 10 audit alert. Các lần upload, xử lý, xem ảnh, xem face crop, sửa OCR, gửi xác thực, xác thực, yêu cầu gửi lại và thay đổi quyền được ghi vào bảng `audit_logs`.

Admin đổi OCR provider tại `/admin/ocr-vendor`; chỉ role `admin` vào được cả trang và thao tác PUT. Lựa chọn trong `system_settings` được đồng bộ qua file state để FastAPI đọc, có audit `ocr.provider_updated`; lỗi đồng bộ khiến cập nhật database rollback. Giao diện này không nhận API key và không kiểm tra kết nối vendor khi lưu. Admin không cần khởi động lại service để đổi lựa chọn provider cho các yêu cầu sau.

Ảnh mã hóa được lưu trên private storage, JSON OCR nằm trong database theo kết nối cấu hình (MySQL hoặc SQLite). Service mã hóa ảnh không mã hóa các JSON OCR ở tầng ứng dụng. Route ảnh luôn kiểm tra Policy, trả `Cache-Control: private, no-store`; hồ sơ đã purge trả 404 cho ảnh. Retention mặc định 30 ngày xóa file ảnh và JSON OCR, giữ bản ghi hồ sơ/audit; cần scheduler hoạt động.

## 7. Chức năng chưa có route/UI

Permission hoặc policy method dự phòng không phải chức năng đã triển khai. Đã có lịch sử phân trang/lọc trạng thái và xuất PDF/CSV. Chưa có tìm kiếm tự do, xóa/lưu trữ hồ sơ thủ công, retry xử lý thủ công hoặc xoay ảnh thủ công. Các script xuất OCR trong `ai-engine/` là công cụ CLI riêng. Cấu hình queue của Laravel hiện chưa được dùng để xử lý upload ảnh chạy nền.

## 8. Quyền xuất báo cáo

- PDF: `GET /documents/{document}/report`, policy `exportReport` phải cho phép. Chỉ chủ hồ sơ có `documents.view-own`, không mang role admin/processor/reviewer, hồ sơ chính chưa purge, trạng thái completed/pending_review/verified/resubmission_required và có ít nhất một normalized value khác rỗng. Controller dùng DomPDF; file `Bao_Cao_Ho_So_{id}.pdf`, audit `document.report_exported`.
- CSV: route thuộc nhóm `role:admin`. Cả hai loại yêu cầu `documents.export-management-csv`; loại OCR cần thêm `documents.export-ocr-csv`. POST bị giới hạn 5 lần/phút. Hai permission được seed cho Admin, không cho Customer hoặc staff.
- CSV chỉ xuất hồ sơ chính, tối đa 5.000 hồ sơ. OCR loại hồ sơ processing/failed, đã purge hoặc không có field chuẩn hóa có giá trị. Không có dữ liệu vẫn trả CSV chỉ có header.
- Bộ lọc ngày dùng `created_at`: bỏ trống cả hai thì không lọc ngày; có from_date thì bắt buộc to_date >= from_date; chỉ to_date thì lấy đến hết ngày đó. Loại giấy tờ lọc bằng đúng giá trị document_type, chưa phân biệt phiên bản CCCD qua card_version.
- Audit CSV: `admin.management_csv_exported` hoặc `admin.ocr_csv_exported`, lưu loại báo cáo/bộ lọc/số dòng.
