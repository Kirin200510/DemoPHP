<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ImageDocument;
use App\Services\ProcessingFailureMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProcessingFailureStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_status_and_bad_images_do_not_distort_system_failure_ratio(): void
    {
        foreach ([ImageDocument::STATUS_COMPLETED, ImageDocument::STATUS_PENDING_REVIEW, ImageDocument::STATUS_VERIFIED, ImageDocument::STATUS_RESUBMISSION_REQUIRED] as $status) {
            $this->recordOutcome($status, 'document.processed');
            $this->recordOutcome($status, 'document.processed');
        }

        $this->recordOutcome(ImageDocument::STATUS_FAILED, 'document.processing_failed', 'ai_engine_unavailable');
        $this->recordOutcome(ImageDocument::STATUS_FAILED, 'document.processing_failed', 'ai_engine_invalid_response');
        for ($index = 0; $index < 6; $index++) {
            $this->recordOutcome(ImageDocument::STATUS_FAILED, 'document.processing_failed', 'invalid_image');
        }

        $statistics = app(ProcessingFailureMonitor::class)->processingStatistics(15);

        $this->assertSame(8, $statistics['completed_count']);
        $this->assertSame(2, $statistics['failed_count']);
        $this->assertSame(6, $statistics['rejected_count']);
        $this->assertSame(10, $statistics['request_count']);
        $this->assertSame(0.2, $statistics['failure_ratio']);
    }

    public function test_only_terminal_events_in_the_window_are_counted_and_legacy_errors_remain_unclassified(): void
    {
        $old = $this->recordOutcome(ImageDocument::STATUS_FAILED, 'document.processing_failed', 'application_error');
        $old->forceFill(['created_at' => now()->subMinutes(16)])->save();
        $recent = $this->recordOutcome(ImageDocument::STATUS_VERIFIED, 'document.processed');
        $recent->auditable->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->recordOutcome(ImageDocument::STATUS_FAILED, 'document.processing_failed');
        ImageDocument::factory()->create(['status' => ImageDocument::STATUS_PROCESSING]);

        $statistics = app(ProcessingFailureMonitor::class)->processingStatistics(15);

        $this->assertSame(1, $statistics['completed_count']);
        $this->assertSame(0, $statistics['failed_count']);
        $this->assertSame(1, $statistics['unclassified_count']);
        $this->assertSame(1, $statistics['request_count']);
        $this->assertSame(0.0, $statistics['failure_ratio']);
    }

    public function test_rejections_alone_do_not_create_a_denominator_or_division_error(): void
    {
        $this->recordOutcome(ImageDocument::STATUS_FAILED, 'document.processing_failed', 'invalid_image');

        $statistics = app(ProcessingFailureMonitor::class)->processingStatistics(15);

        $this->assertSame(0, $statistics['request_count']);
        $this->assertSame(0.0, $statistics['failure_ratio']);
        $this->assertSame(1, $statistics['rejected_count']);
    }

    public function test_alert_uses_the_same_statistics_and_keeps_the_cooldown(): void
    {
        Cache::flush();
        config([
            'services.ai_engine.alert_minimum_requests' => 5,
            'services.ai_engine.alert_failure_ratio' => 0.4,
            'services.ai_engine.alert_window_minutes' => 15,
            'services.ai_engine.alert_cooldown_minutes' => 15,
        ]);
        for ($index = 0; $index < 8; $index++) {
            $this->recordOutcome(ImageDocument::STATUS_VERIFIED, 'document.processed');
            $this->recordOutcome(ImageDocument::STATUS_FAILED, 'document.processing_failed', 'invalid_image');
        }
        $failure = $this->recordOutcome(ImageDocument::STATUS_FAILED, 'document.processing_failed', 'application_error');
        $monitor = app(ProcessingFailureMonitor::class);
        $monitor->recordSystemFailure($failure->auditable, 'application_error');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'alert.ai_engine_failure_rate_high']);

        for ($index = 0; $index < 5; $index++) {
            $this->recordOutcome(ImageDocument::STATUS_FAILED, 'document.processing_failed', 'application_error');
        }
        $monitor->recordSystemFailure($failure->auditable, 'application_error');
        $monitor->recordSystemFailure($failure->auditable, 'application_error');
        $alert = AuditLog::query()->where('action', 'alert.ai_engine_failure_rate_high')->sole();

        $this->assertSame(14, $alert->metadata['attempts']);
        $this->assertSame(6, $alert->metadata['failures']);
        $this->assertSame(8, $alert->metadata['rejected_images']);
        $this->assertSame(round(6 / 14, 4), $alert->metadata['failure_ratio']);
    }

    private function recordOutcome(string $status, string $action, ?string $category = null): AuditLog
    {
        $document = ImageDocument::factory()->create(['status' => $status]);

        return AuditLog::query()->create([
            'auditable_type' => $document->getMorphClass(),
            'auditable_id' => $document->id,
            'action' => $action,
            'metadata' => $category === null ? [] : ['category' => $category],
        ]);
    }
}
