@extends('layouts.app')

@section('title', 'Cấu hình OCR vendor')

@section('content')
    <div class="mb-8 flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div>
            <a href="{{ route('admin.processing-metrics') }}" class="text-sm text-cyan-300 hover:text-cyan-200">← Giám sát xử lý AI</a>
            <h1 class="mt-2 text-3xl font-bold text-white">Cấu hình OCR vendor</h1>
            <p class="mt-1 text-sm text-slate-400">Thay đổi provider OCR cho các hồ sơ tải lên sau thời điểm lưu cấu hình.</p>
        </div>
        <a href="{{ route('admin.audit-logs') }}" class="text-sm text-cyan-300 hover:text-cyan-200">Xem nhật ký thay đổi →</a>
    </div>

    <div class="max-w-3xl overflow-hidden rounded-2xl border border-white/10 bg-white/5">
        <div class="border-b border-white/10 px-6 py-5">
            <h2 class="font-semibold text-white">Provider đang dùng</h2>
            <p class="mt-1 text-sm text-slate-400">Vendor hiện tại: <span class="font-medium text-cyan-200">{{ $currentProvider }}</span></p>
        </div>

        @if (! $settingsTableAvailable)
            <div class="mx-6 mt-6 rounded-xl border border-red-400/30 bg-red-400/10 px-4 py-3 text-sm text-red-100">
                Cơ sở dữ liệu chưa có bảng cấu hình OCR. Chạy <code class="rounded bg-slate-950 px-1.5 py-0.5 text-red-200">php artisan migrate</code> để bật tính năng lưu lựa chọn vendor.
            </div>
        @endif

        <form method="POST" action="{{ route('admin.ocr-vendor.update') }}" class="space-y-5 p-6">
            @csrf
            @method('PUT')

            <div>
                <label for="provider" class="block text-sm font-medium text-slate-200">Chọn OCR vendor</label>
                <select id="provider" name="provider" class="mt-2 w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white focus:border-cyan-400 focus:outline-none">
                    @foreach ($providers as $provider)
                        <option value="{{ $provider }}" @selected(old('provider', $currentProvider) === $provider)>
                            {{ match ($provider) {
                                'local_hybrid' => 'Local Hybrid — PP-OCRv5 + VietOCR',
                                'fpt_ai_reader' => 'FPT.AI Reader',
                                'vnpt_ekyc' => 'VNPT eKYC',
                                default => 'External eKYC (generic adapter)',
                            } }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="rounded-xl border border-amber-400/20 bg-amber-400/5 px-4 py-3 text-sm text-amber-100">
                API key, endpoint và timeout không được nhập hoặc hiển thị tại đây. Chúng vẫn phải được cấu hình an toàn ở biến môi trường của AI engine. Nếu vendor chưa có cấu hình hợp lệ, yêu cầu xử lý mới sẽ báo lỗi thay vì tự dùng nhầm vendor khác.
            </div>

            <button type="submit" @disabled(! $settingsTableAvailable) class="rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-50">
                Lưu OCR vendor
            </button>
        </form>
    </div>
@endsection
