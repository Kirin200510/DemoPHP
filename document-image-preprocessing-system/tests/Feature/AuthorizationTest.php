<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ImageDocument;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private string $ocrProviderStatePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ocrProviderStatePath = tempnam(sys_get_temp_dir(), 'ocr-provider-test-');
        config()->set('services.ai_engine.ocr_provider_state_path', $this->ocrProviderStatePath);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        File::delete($this->ocrProviderStatePath);
        parent::tearDown();
    }

    public function test_guest_must_authenticate_before_uploading(): void
    {
        $this->get(route('documents.create'))
            ->assertRedirect(route('login'));
    }

    public function test_logout_redirects_to_the_configured_application_url(): void
    {
        config()->set('app.url', 'https://localhost:8443');
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer)
            ->post(route('logout'))
            ->assertRedirect('https://localhost:8443/');
    }

    public function test_quick_login_ignores_an_http_intended_url(): void
    {
        config()->set('app.url', 'https://localhost:8443');
        URL::forceRootUrl('https://localhost:8443');
        URL::forceScheme('https');
        $customer = User::factory()->create([
            'email' => 'test@example.com',
            'is_active' => true,
        ]);
        $customer->assignRole('customer');

        $this->withSession(['url.intended' => 'http://localhost:8443/'])
            ->post(route('login.quick', ['role' => 'customer']))
            ->assertRedirect('https://localhost:8443');
    }

    public function test_customer_can_only_view_their_own_document(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('customer');
        $otherCustomer = User::factory()->create();
        $otherCustomer->assignRole('customer');
        $document = ImageDocument::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($otherCustomer)
            ->get(route('documents.show', $document))
            ->assertForbidden();
    }

    public function test_customer_cannot_view_face_crop(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $document = ImageDocument::factory()->create([
            'user_id' => $customer->id,
            'ocr_structured_data' => [
                'face_crop' => [
                    'status' => 'detected',
                    'stored_path' => 'documents/faces/example.jpg',
                ],
            ],
        ]);

        $this->actingAs($customer)
            ->get(route('documents.face-crop', $document))
            ->assertForbidden();
    }

    public function test_processor_can_verify_a_pending_document(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $processor = User::factory()->create();
        $processor->assignRole('processor');
        $document = ImageDocument::factory()->create([
            'user_id' => $customer->id,
            'status' => ImageDocument::STATUS_PENDING_REVIEW,
        ]);

        $this->actingAs($processor)
            ->post(route('documents.verify', $document))
            ->assertRedirect(route('documents.create'));

        $this->assertDatabaseHas('image_documents', [
            'id' => $document->id,
            'status' => ImageDocument::STATUS_VERIFIED,
            'reviewed_by' => $processor->id,
        ]);
    }

    public function test_processor_cannot_view_a_document_that_is_not_pending_review(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $processor = User::factory()->create();
        $processor->assignRole('processor');
        $document = ImageDocument::factory()->create([
            'user_id' => $customer->id,
            'status' => ImageDocument::STATUS_COMPLETED,
        ]);

        $this->actingAs($processor)
            ->get(route('documents.show', $document))
            ->assertForbidden();
    }

    public function test_admin_cannot_update_customer_ocr_data(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $document = ImageDocument::factory()->create([
            'user_id' => $customer->id,
            'status' => ImageDocument::STATUS_PENDING_REVIEW,
            'ocr_structured_data' => [
                'fields' => [
                    'full_name' => [
                        'normalized_value' => 'Nguyễn Văn A',
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->put(route('documents.ocr.update', $document), [
                'fields' => ['full_name' => 'Đã bị sửa'],
            ])
            ->assertForbidden();
    }

    public function test_processor_cannot_update_customer_ocr_data(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $processor = User::factory()->create();
        $processor->assignRole('processor');
        $document = ImageDocument::factory()->create([
            'user_id' => $customer->id,
            'status' => ImageDocument::STATUS_PENDING_REVIEW,
        ]);

        $this->actingAs($processor)
            ->put(route('documents.ocr.update', $document), [
                'fields' => ['full_name' => 'Đã bị sửa'],
            ])
            ->assertForbidden();
    }

    public function test_admin_can_view_processing_metrics_without_document_content(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $success = ImageDocument::factory()->create(['status' => ImageDocument::STATUS_VERIFIED]);
        $failure = ImageDocument::factory()->create(['status' => ImageDocument::STATUS_FAILED]);
        foreach ([[$success, 'document.processed', []], [$failure, 'document.processing_failed', ['category' => 'ai_engine_unavailable']]] as [$document, $action, $metadata]) {
            AuditLog::query()->create([
                'auditable_type' => $document->getMorphClass(),
                'auditable_id' => $document->id,
                'action' => $action,
                'metadata' => $metadata,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.processing-metrics'))
            ->assertOk()
            ->assertSee('Giám sát xử lý AI')
            ->assertSee('50.0%');
    }

    public function test_only_admin_can_update_the_ocr_vendor(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer)
            ->get(route('admin.ocr-vendor'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('admin.ocr-vendor.update'), ['provider' => 'fpt_ai_reader'])
            ->assertRedirect(route('admin.ocr-vendor'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('system_settings', [
            'key' => SystemSetting::OCR_PROVIDER_KEY,
            'value' => 'fpt_ai_reader',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'ocr.provider_updated',
        ]);
        $this->assertSame('fpt_ai_reader', json_decode(File::get($this->ocrProviderStatePath), true)['provider']);

        $this->actingAs($admin)
            ->put(route('admin.ocr-vendor.update'), ['provider' => 'vnpt_ekyc'])
            ->assertSessionHas('success');

        $this->assertSame('vnpt_ekyc', json_decode(File::get($this->ocrProviderStatePath), true)['provider']);
        $this->assertSame('vnpt_ekyc', SystemSetting::ocrProvider());

        $this->actingAs($customer)
            ->put(route('admin.ocr-vendor.update'), ['provider' => 'local_hybrid'])
            ->assertForbidden();

        $this->assertSame('vnpt_ekyc', json_decode(File::get($this->ocrProviderStatePath), true)['provider']);

        $this->actingAs($admin)
            ->put(route('admin.ocr-vendor.update'), ['provider' => 'invalid_provider'])
            ->assertSessionHasErrors('provider');

        $this->assertSame('vnpt_ekyc', json_decode(File::get($this->ocrProviderStatePath), true)['provider']);
    }

    public function test_vendor_update_is_rolled_back_when_provider_sync_fails(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        SystemSetting::query()->create([
            'key' => SystemSetting::OCR_PROVIDER_KEY,
            'value' => 'local_hybrid',
        ]);
        File::partialMock()->shouldReceive('replace')
            ->once()
            ->andThrow(new RuntimeException('State file cannot be written.'));

        $this->actingAs($admin)
            ->put(route('admin.ocr-vendor.update'), ['provider' => 'vnpt_ekyc'])
            ->assertSessionHasErrors('provider')
            ->assertSessionMissing('success');

        $this->assertSame('local_hybrid', SystemSetting::ocrProvider());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ocr.provider_updated']);
    }
}
