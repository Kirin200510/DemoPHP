<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">

    <style>
        @page {
            margin: 22mm 18mm;
        }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 13px;
            color: #1e293b;
            line-height: 1.6;
        }

        h1 {
            font-size: 20px;
            text-align: center;
            color: #0f172a;
            margin-bottom: 5px;
        }

        h2 {
            font-size: 17px;
            color: #0891b2;
            margin-top: 22px;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 6px;
        }
        .subtitle {
            text-align: center;
            color: #64748b;
            font-size: 11px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        td {
            border: 1px solid #cbd5e1;
            padding: 7px;
            vertical-align: top;
            overflow-wrap: break-word;
        }
        td:first-child {
            width: 37%;
            background: #f1f5f9;
            font-weight: bold;
        }
        tr {
            page-break-inside: avoid;
        }
        .note {
            font-size: 13px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <h1>BÁO CÁO KẾT QUẢ NHẬN DIỆN GIẤY TỜ </h1>
    <p class="subtitle">Báo cáo này được tạo tổng hợp từ hệ thống xử lý hình ảnh hồ sơ.</p>
    <h2>&#8544;. Thông tin hồ sơ</h2>
    <table>
        <tr>
            <td>Mã hồ sơ</td>
            <td>{{ $document->id }}</td>
        </tr>
        <tr>
            <td>Loại giấy tờ</td>
            <td>{{ data_get($documentInfo, 'normalized_value', 'Không xác định') }}</td>
        </tr>
        <tr>
            <td>Trạng thái hồ sơ</td>
            <td>{{match($document->status) {
                    'completed' => 'Đã xử lý OCR',
                    'pending_review' => 'Đang chờ xác thực',
                    'verified' => 'Đã xác thực',
                    'resubmission_required' => 'Cần gửi lại hồ sơ',
                    default => 'Không xác định',
                } }}
            </td>
        </tr>
    </table>
    <h2>&#8545;. Thông tin giấy tờ đã xử lý và cập nhật (chuẩn hóa)</h2>
    <table>
        @foreach($fields as $fieldName=>$fieldValue)
            @php
                $value=is_array($fieldValue) ? data_get($fieldValue,'normalized_value') : null;
            @endphp
            @if (is_scalar($value) && trim((string) $value) !== '')
                <tr>
                    <td>{{data_get($fieldValue,'label', $fieldName)}}</td>
                    <td>{{ $value }}</td>
                </tr>
            @endif
        @endforeach
    </table>
    <h2>&#8546;. Kết quả đối chiếu hai mặt</h2>
    @if ($isPaired)
        <p>Hai mặt giấy tờ đã được hệ thống đối chiếu
            và xác định có dữ liệu khớp nhau.</p>
    @else
        <p> Không có kết quả ghép hai mặt thành công
            cho hồ sơ này.</p>
    @endif
    <h2>&#8547;. Ghi chú và lưu ý</h2>
    <p class="note">
        Báo cáo được tạo tự động từ dữ liệu OCR đã chuẩn hóa.
        Kết quả OCR và đối chiếu dữ liệu không thay thế
        việc xác minh tính xác thực pháp lý của giấy tờ.
    </p>

    <p class="note" style="text-align: right; width: 100%; display: block; margin: 0;">
        Ngày xuất báo cáo:
        {{ $generatedAt->format('d/m/Y H:i') }}
    </p>
</body>
</html>
