@extends('layouts.app')

@section('title', 'Xử lý ảnh giấy tờ')

@section('content')
    <section class="grid items-start gap-10 lg:grid-cols-[1fr_1.05fr] lg:gap-16">
        <div class="flex flex-col gap-6 lg:pt-10">
            <span class="w-fit rounded-full border border-cyan-400/25 bg-cyan-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">
                Document preprocessing
            </span>
            <div class="flex flex-col gap-4">
                <h1 class="max-w-xl text-4xl font-bold tracking-tight text-white sm:text-5xl">
                    Làm sạch ảnh giấy tờ chỉ với một lần tải lên.
                </h1>
                <p class="max-w-xl text-base leading-7 text-slate-400 sm:text-lg">
                    Hệ thống tự động xử lý ảnh, nhận dạng nội dung bằng OCR và trả về dữ liệu đã chuẩn hóa.
                </p>
            </div>
            <div class="grid max-w-lg grid-cols-3 gap-3 text-center text-xs text-slate-400">
                <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-4"><strong class="mb-1 block text-lg text-white">01</strong>Chọn ảnh</div>
                <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-4"><strong class="mb-1 block text-lg text-white">02</strong>AI xử lý</div>
                <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-4"><strong class="mb-1 block text-lg text-white">03</strong>Nhận kết quả</div>
            </div>
        </div>

        @can('documents.upload')
        <div class="rounded-3xl border border-white/10 bg-white/[0.06] p-5 shadow-2xl shadow-cyan-950/30 backdrop-blur sm:p-7">
            <form id="document-upload-form" action="{{ route('documents.store') }}" method="POST" enctype="multipart/form-data" class="flex flex-col gap-5">
                @csrf

                <label id="upload-drop-zone" for="image" class="group relative grid min-h-80 cursor-pointer place-items-center overflow-hidden rounded-2xl border-2 border-dashed border-slate-600 bg-slate-900/70 p-6 text-center transition hover:border-cyan-400 hover:bg-cyan-400/5">
                    <input id="image" class="sr-only" type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
                    <img id="image-preview" class="absolute inset-0 hidden size-full object-contain p-3" alt="Xem trước ảnh đã chọn">
                    <span id="upload-placeholder" class="flex flex-col items-center gap-4">
                        <span class="grid size-16 place-items-center rounded-2xl bg-cyan-400/10 text-3xl text-cyan-300 transition group-hover:scale-105">↑</span>
                        <span>
                            <strong class="block text-base font-semibold text-white">Nhấn để chọn ảnh giấy tờ</strong>
                            <span class="mt-1 block text-sm text-slate-400">JPG, JPEG, PNG, WEBP · tối đa 10 MB</span>
                        </span>
                    </span>
                </label>

                <div id="selected-file" class="hidden items-center justify-between gap-3 rounded-xl bg-slate-900/80 px-4 py-3 text-sm">
                    <span id="selected-file-name" class="truncate text-slate-200"></span>
                    <button id="remove-selected-file" type="button" class="shrink-0 text-slate-400 transition hover:text-white">Chọn lại</button>
                </div>

                <button id="process-button" type="submit" disabled class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-cyan-400 px-5 font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-400 disabled:opacity-70 disabled:hover:bg-slate-700">
                    <span id="process-button-label">Xử lý hình ảnh và OCR</span>
                    <span id="process-spinner" class="hidden size-5 animate-spin rounded-full border-2 border-slate-950/30 border-t-slate-950" aria-hidden="true"></span>
                </button>
                <p class="text-center text-xs text-slate-500">Quá trình xử lý ảnh và OCR có thể mất khoảng một phút.</p>
            </form>
        </div>
        @else
            <div class="rounded-3xl border border-white/10 bg-white/[0.06] p-7 shadow-2xl shadow-cyan-950/30 backdrop-blur">
                @role('admin')
                    <span class="inline-flex rounded-full bg-blue-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-blue-200">Chế độ chỉ xem</span>
                    <h2 class="mt-4 text-xl font-semibold text-white">Tra cứu hồ sơ</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">Admin chỉ được xem thông tin hồ sơ và quản lý tài khoản, không upload hoặc xử lý giấy tờ.</p>
                @else
                    <span class="inline-flex rounded-full bg-amber-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-amber-200">Nhân viên xử lý</span>
                    <h2 class="mt-4 text-xl font-semibold text-white">Hàng đợi hồ sơ cần xác thực</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">Chỉ hồ sơ khách hàng đã gửi chờ xác thực mới xuất hiện tại đây.</p>
                @endrole
            </div>
        @endcan
    </section>

    @if ($recentDocuments->isNotEmpty())
        <section class="mt-16 flex flex-col gap-5">
            <div>
                <h2 class="text-xl font-semibold text-white">@role('admin') Hồ sơ gần đây @else @role('processor|reviewer') Hàng đợi hồ sơ cần xác thực @else Hồ sơ của tôi @endrole @endrole</h2>
                <p class="mt-1 text-sm text-slate-400">@role('admin') Chọn hồ sơ để xem thông tin, admin không thực hiện thao tác xử lý. @else @role('processor|reviewer') Mở hồ sơ để xác thực hoặc yêu cầu khách hàng gửi lại kèm lý do. @else Theo dõi tiến trình xử lý, xác thực và xem lại hồ sơ của bạn tại đây. @endrole @endrole</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($recentDocuments as $recentDocument)
                    <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/5 transition hover:-translate-y-1 hover:border-cyan-400/40">
                        <a href="{{ route('documents.show', $recentDocument) }}" class="group block">
                        @if ($recentDocument->processed_path)
                            <img src="{{ route('documents.processed', $recentDocument) }}" alt="Kết quả xử lý #{{ $recentDocument->id }}" class="aspect-[4/3] w-full bg-slate-900 object-contain">
                        @else
                            <div class="grid aspect-[4/3] place-items-center bg-slate-900 px-5 text-center text-sm text-slate-500">Hệ thống đang xử lý hồ sơ</div>
                        @endif
                        <div class="flex items-center justify-between px-4 py-3 text-sm">
                            <span class="text-slate-300">Ảnh #{{ $recentDocument->id }}</span>
                            <span class="text-cyan-300 transition group-hover:translate-x-1">Chi tiết →</span>
                        </div>
                        </a>
                        @php
                            $statusLabel = match ($recentDocument->status) {
                                \App\Models\ImageDocument::STATUS_PROCESSING => 'Đang xử lý',
                                \App\Models\ImageDocument::STATUS_COMPLETED => 'Đã xử lý - chờ gửi xác thực',
                                \App\Models\ImageDocument::STATUS_PENDING_REVIEW => 'Đang chờ xác thực',
                                \App\Models\ImageDocument::STATUS_VERIFIED => 'Đã xác thực',
                                \App\Models\ImageDocument::STATUS_RESUBMISSION_REQUIRED => 'Cần gửi lại hồ sơ',
                                \App\Models\ImageDocument::STATUS_FAILED => 'Xử lý thất bại',
                                default => 'Đang cập nhật',
                            };
                            $statusClass = match ($recentDocument->status) {
                                \App\Models\ImageDocument::STATUS_VERIFIED => 'bg-emerald-400/10 text-emerald-300',
                                \App\Models\ImageDocument::STATUS_PENDING_REVIEW => 'bg-amber-400/10 text-amber-300',
                                \App\Models\ImageDocument::STATUS_RESUBMISSION_REQUIRED => 'bg-orange-400/10 text-orange-300',
                                \App\Models\ImageDocument::STATUS_FAILED => 'bg-red-400/10 text-red-300',
                                default => 'bg-cyan-400/10 text-cyan-300',
                            };
                        @endphp
                        <div class="border-t border-white/10 px-4 py-3">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span>
                        </div>
                        @can('review', $recentDocument)
                            <div class="border-t border-amber-400/20 bg-amber-400/[0.06] px-4 py-3">
                                <a href="{{ route('documents.show', $recentDocument) }}#review-actions" class="inline-flex w-full items-center justify-center rounded-lg bg-amber-400 px-3 py-2 text-xs font-bold text-slate-950 transition hover:bg-amber-300">Xác thực hồ sơ</a>
                            </div>
                        @endcan
                    </div>
                @endforeach
            </div>
        </section>
    @endif
@endsection
