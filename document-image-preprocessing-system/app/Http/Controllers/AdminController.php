<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    public function users(): View
    {
        return view('admin.users', [
            'users' => User::query()->with('roles')->latest('id')->paginate(20),
            'roles' => Role::query()->where('guard_name', 'web')->orderBy('name')->get(),
        ]);
    }

    public function updateAccess(Request $request, User $user): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'role' => ['required', 'string', 'exists:roles,name'],
            'is_active' => ['required', 'boolean'],
        ])->validate();

        if ($user->is($request->user()) && $validated['is_active'] === false) {
            return back()->withErrors(['access' => 'Không thể tự khóa tài khoản Admin đang đăng nhập.']);
        }

        $user->forceFill(['is_active' => $validated['is_active']])->save();
        $user->syncRoles([$validated['role']]);

        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'account.access_updated',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => $user->getKey(),
            'metadata' => [
                'target_user_id' => $user->id,
                'role' => $validated['role'],
                'is_active' => $validated['is_active'],
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Thông tin quyền truy cập đã được cập nhật.');
    }

    public function auditLogs(): View
    {
        return view('admin.audit-logs', [
            'auditLogs' => AuditLog::query()->with(['user', 'auditable'])->latest('id')->paginate(30),
        ]);
    }
}
