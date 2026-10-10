<?php

namespace Tests\Feature;

use App\Models\ImageDocument;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_guest_must_authenticate_before_uploading(): void
    {
        $this->get(route('documents.create'))
            ->assertRedirect(route('login'));
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
}
