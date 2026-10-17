@extends('layouts.app')

@section('title', 'Giám sát xử lý AI')

@section('content')
    <div class="mb-8 flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div>
            <a href="{{ route('admin.users') }}" class="text-sm text-cyan-300 hover:text-cyan-200">← Quản lý tài khoản</a>
            <h1 class="mt-2 text-3xl font-bold text-white">Giám sát xử lý AI</h1>
            <p class="mt-1 text-sm text-slate-400">Thống kê kết quả xử lý trong {{ $windowMinutes }} phút gần nhất, không phụ thuộc trạng thái xác thực hồ sơ.</p>
        </div>
        <div class="flex gap-4 text-sm">
            <a href="{{ route('admin.ocr-vendor') }}" class="text-cyan-300 hover:text-cyan-200">Cấu hình OCR vendor →</a>
            <a href="{{ route('admin.audit-logs') }}" class="text-cyan-300 hover:text-cyan-200">Xem nhật ký truy cập →</a>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-5"><p class="text-xs uppercase tracking-wide text-slate-500">Lượt xử lý tính tỷ lệ</p><p class="mt-2 text-3xl font-semibold text-white">{{ $requestCount }}</p></div>
        <div class="rounded-2xl border border-emerald-400/20 bg-emerald-400/5 p-5"><p class="text-xs uppercase tracking-wide text-emerald-300">Thành công</p><p class="mt-2 text-3xl font-semibold text-white">{{ $completedCount }}</p></div>
        <div class="rounded-2xl border border-red-400/20 bg-red-400/5 p-5"><p class="text-xs uppercase tracking-wide text-red-300">Lỗi hệ thống</p><p class="mt-2 text-3xl font-semibold text-white">{{ $failedCount }}</p></div>
        <div class="rounded-2xl border border-amber-400/20 bg-amber-400/5 p-5"><p class="text-xs uppercase tracking-wide text-amber-300">Tỷ lệ lỗi hệ thống</p><p class="mt-2 text-3xl font-semibold text-white">{{ number_format($failureRatio * 100, 1) }}%</p></div>
    </div>

    <div class="mt-4 rounded-xl border border-white/10 px-5 py-4 text-sm text-slate-400">
        <p>Tỷ lệ lỗi = Lượt lỗi hệ thống / (Lượt thành công + Lượt lỗi hệ thống). Mỗi ảnh được tính một kết quả cuối sau các lần tự thử lại.</p>
        <p class="mt-2">Ảnh bị từ chối do chất lượng: {{ $rejectedCount }}. Lượt lỗi chưa xác định nguyên nhân: {{ $unclassifiedCount }}. Hai nhóm này không được tính vào tỷ lệ lỗi hệ thống.</p>
        <p class="mt-2">Số liệu dựa trên lịch sử xử lý được ghi nhận; hồ sơ chuyển sang chờ duyệt hoặc đã xác thực vẫn giữ kết quả xử lý ban đầu.</p>
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-white/10 bg-white/5">
        <div class="border-b border-white/10 px-5 py-4"><h2 class="font-semibold text-white">Cảnh báo tỷ lệ lỗi AI Engine</h2><p class="mt-1 text-sm text-slate-400">Cảnh báo được tạo khi vượt ngưỡng cấu hình; không chứa ảnh hoặc dữ liệu OCR.</p></div>
        @forelse ($alerts as $alert)
            <div class="border-b border-white/10 px-5 py-4 last:border-0"><p class="text-sm font-medium text-amber-200">{{ $alert->created_at->format('d/m/Y H:i:s') }}</p><p class="mt-1 text-xs text-slate-400">{{ json_encode($alert->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</p></div>
        @empty
            <p class="px-5 py-6 text-sm text-slate-400">Chưa có cảnh báo tỷ lệ lỗi trong hệ thống.</p>
        @endforelse
    </div>
@endsection
