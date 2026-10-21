@extends('layouts.app')

@section('title', 'Xuất dữ liệu CSV')

@section('content')
<div class="mx-auto max-w-3xl">

    <h1 class="mb-2 text-3xl font-bold text-white">
        Xuất dữ liệu CSV
    </h1>

    <p class="mb-6 text-sm text-slate-400">
        Lựa chọn loại báo cáo và điều kiện lọc hồ sơ.
    </p>
    @if($errors->any()) 
        <div class="mb-5 rounded-xl border border-red-400/30
                    bg-red-400/10 p-4 text-red-200">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif
    <form   action="{{ route('admin.exports.csv') }}" 
            method="POST" 
            class="space-y-6 rounded-2xl border border-white/10 bg-slate-900 p-6">
        @csrf
            <!-- LOẠI BÁO CÁO -->
        <div>
            <label for="report_type"
                   class="mb-2 block font-semibold text-white">
                Loại báo cáo
            </label>

            <select id="report_type"
                    name="report_type"
                    class="w-full rounded-lg border border-slate-600
                           bg-slate-800 p-3 text-white">

                <option value="management">
                    Danh sách quản lý hồ sơ
                </option>

                <option value="ocr">
                    Dữ liệu OCR đã chuẩn hóa
                </option>
            </select>
        </div>
        <!-- TRẠNG THÁI -->
        <div>
            <label for="status"
                   class="mb-2 block font-semibold text-white">
                Trạng thái hồ sơ
            </label>

            <select id="status"
                    name="status"
                    class="w-full rounded-lg border border-slate-600
                           bg-slate-800 p-3 text-white">

                <option value="all">
                    Tất cả trạng thái
                </option>

                @foreach ($statuses as $item)
                    <option value="{{ $item }}">
                        {{ match ($item) {
                            'processing' => 'Đang xử lý',
                            'completed' => 'Đã xử lý OCR',
                            'failed' => 'Xử lý thất bại',
                            'pending_review' => 'Chờ xác thực',
                            'verified' => 'Đã xác thực',
                            'resubmission_required' => 'Yêu cầu gửi lại',
                            default => $item,
                        } }}
                    </option>
                @endforeach
            </select>
        </div>
        <!-- LOẠI GIẤY TỜ -->
        <div>
            <label for="document_type"
                   class="mb-2 block font-semibold text-white">
                Loại giấy tờ
            </label>

            <select id="document_type"
                    name="document_type"
                    class="w-full rounded-lg border border-slate-600
                           bg-slate-800 p-3 text-white">

                <option value="all">
                    Tất cả loại giấy tờ
                </option>

                @foreach ($documentTypes as $type)
                    <option value="{{ $type }}">
                        {{ match ($type) {
                            'citizen_identity_card_new' => 'CCCD mới',
                            'citizen_identity_card_legacy' => 'CCCD cũ',
                            'unknown' => 'Chưa xác định',
                            default => $type,
                        } }}
                    </option>
                @endforeach
            </select>
        </div>
        <!-- KHOẢNG NGÀY -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="from_date"
                       class="mb-2 block font-semibold text-white">
                    Từ ngày
                </label>
                <input type="date"
                       id="from_date"
                       name="from_date"
                       value="{{ old('from_date') }}"
                       class="w-full rounded-lg border border-slate-600
                              bg-slate-800 p-3 text-white">
            </div>

            <div>
                <label for="to_date"
                       class="mb-2 block font-semibold text-white">
                    Đến ngày
                </label>
                <input type="date"
                       id="to_date"
                       name="to_date"
                       value="{{ old('to_date') }}"
                       class="w-full rounded-lg border border-slate-600
                              bg-slate-800 p-3 text-white">
            </div>
        </div>
        <p class="text-xs text-slate-400">Để trống hai ô ngày nếu không muốn lọc thời gian. Nếu nhập Từ ngày, bạn phải nhập Đến ngày.</p>
        <button type="submit"
                class="w-full rounded-xl bg-cyan-400
                       px-5 py-3 font-semibold text-slate-950
                       transition hover:bg-cyan-300">
            Xuất file CSV
        </button>
    </form>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        
        const report = document.getElementById('report_type');
        const status = document.getElementById('status');

        const from = document.getElementById('from_date');
        const to = document.getElementById('to_date');

        // Cập nhật trạng thái theo loại báo cáo.
        function updateStatuses() {
            const isOcr = report.value === 'ocr';

            for (const option of status.options) {
                option.disabled = isOcr
                    && ['processing', 'failed'].includes(option.value);
            }

            if (status.selectedOptions[0]?.disabled) {
                status.value = 'all';
            }
        }

        // Không cho ngày bắt đầu lớn hơn ngày kết thúc.
        function validateDates() {
            to.required = Boolean(from.value);
            to.min = from.value;

            if (from.value && to.value && from.value > to.value) {
                to.setCustomValidity(
                    'Ngày kết thúc phải bằng hoặc sau ngày bắt đầu.'
                );
            } else {
                to.setCustomValidity('');
            }
        }

        report.addEventListener('change', updateStatuses);
        from.addEventListener('change', validateDates);
        to.addEventListener('change', validateDates);

        updateStatuses();
        validateDates();
    });
</script>
@endsection
