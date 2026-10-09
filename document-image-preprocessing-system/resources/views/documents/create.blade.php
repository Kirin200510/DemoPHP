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

        <div class="rounded-3xl border border-white/10 bg-white/[0.06] p-5 shadow-2xl shadow-cyan-950/30 backdrop-blur sm:p-7">
            @if ($errors->any())
                <div class="mb-5 rounded-xl border border-red-400/25 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

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
    </section>

    @if ($recentDocuments->isNotEmpty())
        <section class="mt-16 flex flex-col gap-5">
            <div>
                <h2 class="text-xl font-semibold text-white">Kết quả gần đây</h2>
                <p class="mt-1 text-sm text-slate-400">Các ảnh đã xử lý thành công được lưu trong hệ thống.</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($recentDocuments as $recentDocument)
                    <a href="{{ route('documents.show', $recentDocument) }}" class="group overflow-hidden rounded-2xl border border-white/10 bg-white/5 transition hover:-translate-y-1 hover:border-cyan-400/40">
                        <img src="{{ route('documents.processed', $recentDocument) }}" alt="Kết quả xử lý #{{ $recentDocument->id }}" class="aspect-[4/3] w-full bg-slate-900 object-contain">
                        <div class="flex items-center justify-between px-4 py-3 text-sm">
                            <span class="text-slate-300">Ảnh #{{ $recentDocument->id }}</span>
                            <span class="text-cyan-300 transition group-hover:translate-x-1">Xem →</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
@endsection
