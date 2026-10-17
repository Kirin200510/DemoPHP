<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ProcessingFailureMonitor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Throwable;

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

    public function processingMetrics(ProcessingFailureMonitor $failureMonitor): View
    {
        $windowMinutes = max(1, (int) config('services.ai_engine.alert_window_minutes'));
        $statistics = $failureMonitor->processingStatistics($windowMinutes);

        return view('admin.processing-metrics', [
            'windowMinutes' => $windowMinutes,
            'completedCount' => $statistics['completed_count'],
            'failedCount' => $statistics['failed_count'],
            'rejectedCount' => $statistics['rejected_count'],
            'unclassifiedCount' => $statistics['unclassified_count'],
            'requestCount' => $statistics['request_count'],
            'failureRatio' => $statistics['failure_ratio'],
            'alerts' => AuditLog::query()
                ->where('action', 'alert.ai_engine_failure_rate_high')
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    public function ocrVendor(): View
    {
        return view('admin.ocr-vendor', [
            'currentProvider' => SystemSetting::ocrProvider(),
            'providers' => SystemSetting::ocrProviders(),
            'settingsTableAvailable' => Schema::hasTable('system_settings'),
        ]);
    }

    public function updateOcrVendor(Request $request): RedirectResponse
    {
        if (! Schema::hasTable('system_settings')) {
            return back()->withErrors([
                'provider' => 'Chưa có bảng cấu hình OCR. Hãy chạy php artisan migrate rồi thử lại.',
            ]);
        }

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(SystemSetting::ocrProviders())],
        ]);

        $previousProvider = SystemSetting::ocrProvider();
        $selectedProvider = $validated['provider'];

        DB::transaction(function () use ($selectedProvider, $previousProvider, $request): void {
            SystemSetting::query()->updateOrCreate(
                ['key' => SystemSetting::OCR_PROVIDER_KEY],
                ['value' => $selectedProvider],
            );

            AuditLog::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'ocr.provider_updated',
                'metadata' => [
                    'previous_provider' => $previousProvider,
                    'provider' => $selectedProvider,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            try {
                SystemSetting::syncOcrProviderState($selectedProvider);
            } catch (Throwable $exception) {
                report($exception);

                throw ValidationException::withMessages([
                    'provider' => 'Không thể đồng bộ OCR provider với AI Engine. Kiểm tra quyền ghi tệp cấu hình dùng chung rồi thử lại.',
                ]);
            }
        });

        return back()->with('success', 'Đã cập nhật OCR vendor và provider trên metrics. Lựa chọn mới áp dụng cho các ảnh tải lên sau thời điểm này.');
    }
}
