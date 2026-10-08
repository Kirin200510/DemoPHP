@extends('layouts.app')

@section('title', 'Kết quả xử lý #'.$document->id)

@section('content')
    <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div class="flex flex-col gap-2">
            <a href="{{ route('documents.create') }}" class="text-sm text-cyan-300 transition hover:text-cyan-200">← Xử lý ảnh khác</a>
            <h1 class="text-3xl font-bold tracking-tight text-white">Kết quả xử lý #{{ $document->id }}</h1>
            <p class="text-sm text-slate-400">Tạo lúc {{ $document->created_at->format('H:i · d/m/Y') }}</p>
        </div>
        <span class="w-fit rounded-full px-3 py-1 text-xs font-semibold {{ $document->status === \App\Models\ImageDocument::STATUS_COMPLETED ? 'bg-emerald-400/10 text-emerald-300' : 'bg-red-400/10 text-red-300' }}">
            {{ $document->status === \App\Models\ImageDocument::STATUS_COMPLETED ? 'Đã xử lý' : 'Xử lý thất bại' }}
        </span>
    </div>

    @if (session('success'))
        <div class="mb-6 rounded-xl border border-emerald-400/25 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200" role="status">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->has('processing'))
        <div class="mb-6 rounded-xl border border-red-400/25 bg-red-400/10 px-4 py-3 text-sm text-red-200" role="alert">
            {{ $errors->first('processing') }}
        </div>
    @endif

    <section class="grid gap-6 {{ $document->processed_path ? 'lg:grid-cols-2' : '' }}">
        <article class="overflow-hidden rounded-2xl border border-white/10 bg-white/5">
            <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                <div>
                    <h2 class="font-semibold text-white">Ảnh gốc</h2>
                    <p class="text-xs text-slate-400">Tệp người dùng tải lên</p>
                </div>
                <span class="rounded-full bg-white/5 px-2.5 py-1 text-xs text-slate-400">Original</span>
            </div>
            <div class="grid min-h-96 place-items-center bg-slate-900/60 p-4">
                <img src="{{ route('documents.original', $document) }}" alt="Ảnh giấy tờ gốc" class="max-h-[36rem] w-full object-contain">
            </div>
        </article>

        @if ($document->processed_path)
            <article class="overflow-hidden rounded-2xl border border-cyan-400/25 bg-cyan-400/[0.04]">
                <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                    <div>
                        <h2 class="font-semibold text-white">Ảnh sau xử lý</h2>
                        <p class="text-xs text-slate-400">Kết quả cuối từ AI engine</p>
                    </div>
                    <span class="rounded-full bg-cyan-400/10 px-2.5 py-1 text-xs text-cyan-300">Final</span>
                </div>
                <div class="grid min-h-96 place-items-center bg-slate-900/60 p-4">
                    <img src="{{ route('documents.processed', $document) }}" alt="Ảnh giấy tờ sau xử lý" class="max-h-[36rem] w-full object-contain">
                </div>
            </article>
        @endif
    </section>

    <div class="mt-7 flex flex-wrap gap-3">
        @if ($document->processed_path)
            <a href="{{ route('documents.processed', $document) }}" download="document-{{ $document->id }}-processed.jpg" class="inline-flex h-11 items-center justify-center rounded-xl bg-cyan-400 px-5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                Tải ảnh kết quả
            </a>
        @endif
        <a href="{{ route('documents.create') }}" class="inline-flex h-11 items-center justify-center rounded-xl border border-white/15 bg-white/5 px-5 text-sm font-semibold text-white transition hover:bg-white/10">
            Chọn ảnh mới
        </a>
    </div>
@endsection
