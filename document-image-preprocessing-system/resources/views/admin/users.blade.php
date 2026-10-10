@extends('layouts.app')

@section('title', 'Quản lý tài khoản')

@section('content')
    <div class="mb-8 flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div>
            <a href="{{ route('documents.create') }}" class="text-sm text-cyan-300 hover:text-cyan-200">← Về trang xử lý</a>
            <h1 class="mt-2 text-3xl font-bold text-white">Quản lý tài khoản</h1>
            <p class="mt-1 text-sm text-slate-400">Mở/khóa tài khoản và gán vai trò.</p>
        </div>
        <a href="{{ route('admin.audit-logs') }}" class="text-sm text-cyan-300 hover:text-cyan-200">Xem nhật ký truy cập →</a>
    </div>

    @if (session('success'))
        <div class="mb-5 rounded-xl border border-emerald-400/25 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-5 rounded-xl border border-red-400/25 bg-red-400/10 px-4 py-3 text-sm text-red-200">{{ $errors->first() }}</div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/5">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-white/10 text-left text-sm">
                <thead class="bg-slate-950/60 text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="px-5 py-3">Tài khoản</th><th class="px-5 py-3">Vai trò</th><th class="px-5 py-3">Trạng thái</th><th class="px-5 py-3">Cập nhật</th></tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @foreach ($users as $user)
                        <tr class="align-top">
                            <td class="px-5 py-4"><p class="font-medium text-white">{{ $user->name }}</p><p class="text-xs text-slate-400">{{ $user->email }}</p></td>
                            <td class="px-5 py-4 text-slate-300">{{ $user->roles->pluck('name')->join(', ') ?: 'Chưa gán' }}</td>
                            <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs {{ $user->is_active ? 'bg-emerald-400/10 text-emerald-300' : 'bg-red-400/10 text-red-300' }}">{{ $user->is_active ? 'Đang hoạt động' : 'Đã khóa' }}</span></td>
                            <td class="px-5 py-4">
                                <form method="POST" action="{{ route('admin.users.access', $user) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf @method('PUT')
                                    <select name="role" class="h-9 rounded-lg border border-white/10 bg-slate-950 px-2 text-xs text-white">
                                        @foreach ($roles as $role)<option value="{{ $role->name }}" @selected($user->hasRole($role->name))>{{ $role->name }}</option>@endforeach
                                    </select>
                                    <label class="flex items-center gap-1 text-xs text-slate-400"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($user->is_active) class="rounded border-white/20 bg-slate-900 text-cyan-400"> Hoạt động</label>
                                    <button class="h-9 rounded-lg bg-cyan-400 px-3 text-xs font-semibold text-slate-950 hover:bg-cyan-300">Lưu</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="border-t border-white/10 px-5 py-4">{{ $users->links() }}</div>
    </div>
@endsection
