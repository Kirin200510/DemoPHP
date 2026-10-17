<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ImageDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessingFailureMonitor
{
    /**
     * Count terminal processing events independently of the dossier's review status.
     *
     * @return array{completed_count: int, failed_count: int, rejected_count: int, unclassified_count: int, request_count: int, total_count: int, failure_ratio: float}
     */
    public function processingStatistics(int $windowMinutes): array
    {
        $events = AuditLog::query()
            ->where('created_at', '>=', now()->subMinutes(max(1, $windowMinutes)))
            ->where('created_at', '<=', now())
            ->where('auditable_type', (new ImageDocument)->getMorphClass())
            ->whereIn('action', ['document.processed', 'document.processing_failed'])
            ->get(['action', 'metadata']);

        $completedCount = 0;
        $failedCount = 0;
        $rejectedCount = 0;
        $unclassifiedCount = 0;

        foreach ($events as $event) {
            if ($event->action === 'document.processed') {
                $completedCount++;

                continue;
            }

            $category = data_get($event->metadata, 'category');

            if ($category === 'invalid_image') {
                $rejectedCount++;
            } elseif (in_array($category, ['ai_engine_unavailable', 'ai_engine_invalid_response', 'application_error'], true)) {
                $failedCount++;
            } else {
                $unclassifiedCount++;
            }
        }

        $requestCount = $completedCount + $failedCount;

        return [
            'completed_count' => $completedCount,
            'failed_count' => $failedCount,
            'rejected_count' => $rejectedCount,
            'unclassified_count' => $unclassifiedCount,
            'request_count' => $requestCount,
            'total_count' => $events->count(),
            'failure_ratio' => $requestCount > 0 ? (float) $failedCount / $requestCount : 0.0,
        ];
    }

    public function recordSystemFailure(ImageDocument $document, string $category): void
    {
        $windowMinutes = max(1, (int) config('services.ai_engine.alert_window_minutes'));
        $minimumRequests = max(1, (int) config('services.ai_engine.alert_minimum_requests'));
        $failureRatioThreshold = (float) config('services.ai_engine.alert_failure_ratio');
        $statistics = $this->processingStatistics($windowMinutes);
        $attempts = $statistics['request_count'];

        if ($attempts < $minimumRequests) {
            return;
        }

        $failures = $statistics['failed_count'];
        $failureRatio = $statistics['failure_ratio'];

        if ($failureRatio < $failureRatioThreshold) {
            return;
        }

        $cooldownMinutes = max(1, (int) config('services.ai_engine.alert_cooldown_minutes'));
        $cacheKey = 'alerts:ai-engine-failure-rate';

        if (! Cache::add($cacheKey, true, now()->addMinutes($cooldownMinutes))) {
            return;
        }

        $metadata = [
            'category' => $category,
            'window_minutes' => $windowMinutes,
            'attempts' => $attempts,
            'failures' => $failures,
            'rejected_images' => $statistics['rejected_count'],
            'unclassified_failures' => $statistics['unclassified_count'],
            'failure_ratio' => round($failureRatio, 4),
            'threshold' => $failureRatioThreshold,
        ];

        AuditLog::query()->create([
            'user_id' => request()->user()?->id,
            'action' => 'alert.ai_engine_failure_rate_high',
            'auditable_type' => $document->getMorphClass(),
            'auditable_id' => $document->getKey(),
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        Log::critical('AI engine processing failure rate is above threshold.', $metadata);
    }
}
