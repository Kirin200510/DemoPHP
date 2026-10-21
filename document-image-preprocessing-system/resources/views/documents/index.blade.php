@extends('layouts.app')
@section('title', 'Tất cả hồ sơ của tôi')
@section('content')
<section class="space-y-6">

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">
                Tất cả hồ sơ của tôi
            </h1>
            <p class="mt-2 text-sm text-slate-400"> Tổng cộng {{$documents->total()}} hồ sơ</p>
        </div>
        <a href= "{{route('documents.create')}}" class="btn btn-primary"  class="rounded-lg border border-white/20 px-4 py-2 text-sm text-white hover:bg-white/10">&larr;Quay lại</a>
    </div>
    @php 
        $filters=[
            'all'=>'Tất cả',
            \App\Models\ImageDocument::STATUS_PROCESSING=>'Đang xử lý',
            \App\Models\ImageDocument::STATUS_COMPLETED=>'Đã xử lý',
            \App\Models\ImageDocument::STATUS_PENDING_REVIEW=>'Đang chờ xác thực',
            \App\Models\ImageDocument::STATUS_VERIFIED=>'Đã xác thực',
            \App\Models\ImageDocument::STATUS_RESUBMISSION_REQUIRED=>'Cần gửi lại hồ sơ',
            \App\Models\ImageDocument::STATUS_FAILED=>'Xử lý thất bại',
        ];
    @endphp  
    <div class="flex flex-wrap gap-2">
        @foreach ($filters as $value=>$label)
            <a href="{{ route('documents.index', $value === 'all' ? [] : ['status' => $value]) }}"
           @class([
               'rounded-xl border px-4 py-2 text-sm font-semibold transition',
               'border-cyan-400 bg-cyan-400 text-slate-950'
                   => $status === $value,
               'border-slate-700 bg-slate-900 text-slate-300 hover:border-cyan-400 hover:text-cyan-300'
                   => $status !== $value,])>
            {{ $label }}
        </a>
        @endforeach
    </div>
    @if ($documents->isEmpty())
        <div class="rounded-xl border border-white/10 bg-white/5 p-10 text-center">
            <p class="text-slate-400">
                @if ($status === 'all')
                    Bạn chưa có hồ sơ nào.
                @else
                    Không có hồ sơ nào với trạng thái đã chọn.
                @endif
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($documents as $document)
                <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/5">
                    <a href="{{ route('documents.show', $document) }}"> 
                        @if ($document->processed_path && !$document->purged_at)
                            <img src="{{route('documents.processed',$document)}}"
                                alt="Ảnh hồ sơ #{{$document->id}}"  
                                class="aspect-[4/3] w-full bg-slate-900 object-contain" 
                                loading="lazy">
                        @else
                            <div class="grid aspect-[4/3] place-items-center bg-slate-900 text-sm text-slate-400">
                                @if ($document->purged_at)
                                    Ảnh đã hết thời gian lưu trữ
                                @else
                                    Chưa có ảnh xử lý
                                @endif
                            </div>
                        @endif
                    </a>
                    <div class="space-y-3 p-4">
                        <h2 class="font-semibold text-white">
                            Hồ sơ #{{ $document->id }}
                        </h2>
                        @php
                            $statusLabel = match ($document->status) {
                                \App\Models\ImageDocument::STATUS_PROCESSING => 'Đang xử lý',
                                \App\Models\ImageDocument::STATUS_COMPLETED => 'Đã xử lý - chờ gửi xác thực',
                                \App\Models\ImageDocument::STATUS_PENDING_REVIEW => 'Đang chờ xác thực',
                                \App\Models\ImageDocument::STATUS_VERIFIED => 'Đã xác thực',
                                \App\Models\ImageDocument::STATUS_RESUBMISSION_REQUIRED => 'Cần gửi lại hồ sơ',
                                \App\Models\ImageDocument::STATUS_FAILED => 'Xử lý thất bại',
                                default => 'Đang cập nhật',
                            };
                        @endphp

                        <p class="text-sm text-cyan-300">
                            Trạng thái: {{ $statusLabel }}
                        </p>
                        <a href="{{ route('documents.show', $document) }}"
                           class="inline-block rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-300">
                            Xem chi tiết 
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="pt-6 document-pagination">
            {{ $documents->links() }}
        </div>
    @endif
</section>
@endsection