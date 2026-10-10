@extends('layouts.app')

@section('title', 'Nhật ký truy cập')

@section('content')
    <div class="mb-8">
        <a href="{{ route('admin.users') }}" class="text-sm text-cyan-300 hover:text-cyan-200">← Quản lý tài khoản</a>
        <h1 class="mt-2 text-3xl font-bold text-white">Nhật ký truy cập</h1>
        <p class="mt-1 text-sm text-slate-400">Theo dõi các hành động trên tài khoản và hồ sơ.</p>
    </div>

    <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/5">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-white/10 text-left text-sm">
                <thead class="bg-slate-950/60 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Thời gian</th><th class="px-5 py-3">Người dùng</th><th class="px-5 py-3">Hành động</th><th class="px-5 py-3">IP</th></tr></thead>
                <tbody class="divide-y divide-white/10">
                    @foreach ($auditLogs as $log)
                        <tr><td class="whitespace-nowrap px-5 py-3 text-slate-400">{{ $log->created_at->format('d/m/Y H:i:s') }}</td><td class="px-5 py-3 text-slate-200">{{ $log->user?->email ?? 'Hệ thống' }}</td><td class="px-5 py-3 font-mono text-xs text-cyan-200">{{ $log->action }}</td><td class="px-5 py-3 text-slate-400">{{ $log->ip_address ?? '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="border-t border-white/10 px-5 py-4">{{ $auditLogs->links() }}</div>
    </div>
@endsection
