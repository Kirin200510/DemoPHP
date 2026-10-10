<?php

namespace Tests\Feature;

use App\Models\ImageDocument;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentProcessingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('customer');
        $this->actingAs($user);
    }

    public function test_upload_page_displays_the_processing_form(): void
    {
        $response = $this->get(route('documents.create'));

        $response
            ->assertOk()
            ->assertSee('Xử lý hình ảnh')
            ->assertSee('name="image"', false)
            ->assertSee('type="submit" disabled', false);
    }

    public function test_valid_image_is_processed_stored_and_recorded(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        Http::fake([
            'http://ai-engine.test/process' => Http::response($this->successfulAiResponse()),
        ]);

        $response = $this->post(route('documents.store'), [
            'image' => $this->fakePng('cccd.png'),
        ]);
        $document = ImageDocument::query()->sole();

        $response
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('success', 'Hình ảnh và dữ liệu OCR đã được xử lý, chuẩn hóa và lưu thành công.');
        $this->assertSame(ImageDocument::STATUS_COMPLETED, $document->status);
        $this->assertNotNull($document->processed_path);
        $faceCropPath = $document->ocr_structured_data['face_crop']['stored_path'];
        $this->assertSame('NGUYỄN VĂN A', $document->ocr_raw_data['full_text']);
        $this->assertSame(
            'Nguyễn Văn A',
            $document->ocr_structured_data['fields']['full_name']['normalized_value'],
        );
        $this->assertSame(
            '012345678901',
            $document->ocr_structured_data['fields']['cccd_number']['normalized_value'],
        );
        Storage::disk('local')->assertExists($document->original_path);
        Storage::disk('local')->assertExists($document->processed_path);
        Storage::disk('local')->assertExists($faceCropPath);
        $this->assertSame(
            'processed-jpeg-content',
            Storage::disk('local')->get($document->processed_path),
        );
        $this->assertSame(
            'face-crop-jpeg-content',
            Storage::disk('local')->get($faceCropPath),
        );
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ai-engine.test/process'
            && $request->hasFile('file', filename: 'cccd.png'));

        $this->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('Dữ liệu OCR đã chuẩn hóa')
            ->assertDontSee('Khuôn mặt được trích xuất')
            ->assertSee('Toàn bộ nội dung OCR đọc được')
            ->assertSee('Số/No')
            ->assertSee('Nguyễn Văn A');

        $this->get(route('documents.face-crop', $document))
            ->assertForbidden();
    }

    public function test_ai_engine_failure_marks_document_as_failed_without_processed_image(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        Http::fake([
            'http://ai-engine.test/process' => Http::response(
                ['detail' => 'Pipeline failed'],
                500,
            ),
        ]);

        $response = $this->post(route('documents.store'), [
            'image' => $this->fakePng('failed-document.png'),
        ]);
        $document = ImageDocument::query()->sole();

        $response
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHasErrors([
                'processing' => 'AI engine không thể xử lý hình ảnh. Hãy kiểm tra server AI rồi thử lại.',
            ]);
        $this->assertSame(ImageDocument::STATUS_FAILED, $document->status);
        $this->assertNull($document->processed_path);
        $this->assertNull($document->ocr_raw_data);
        $this->assertNull($document->ocr_structured_data);
        Storage::disk('local')->assertExists($document->original_path);
        Http::assertSentCount(1);
    }

    public function test_non_image_upload_is_rejected_without_calling_ai_engine(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();

        $response = $this->from(route('documents.create'))->post(route('documents.store'), [
            'image' => UploadedFile::fake()->createWithContent('notes.txt', 'not an image'),
        ]);

        $response
            ->assertRedirect(route('documents.create'))
            ->assertSessionHasErrors(['image']);
        $this->assertDatabaseCount('image_documents', 0);
        Http::assertNothingSent();
    }

    private function fakePng(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulAiResponse(): array
    {
        return [
            'status' => 'completed',
            'processed_image' => [
                'filename' => 'document_final.jpg',
                'media_type' => 'image/jpeg',
                'base64' => base64_encode('processed-jpeg-content'),
            ],
            'ocr' => [
                'raw' => [
                    'status' => 'completed',
                    'full_text' => 'NGUYỄN VĂN A',
                    'lines' => [
                        [
                            'index' => 0,
                            'text' => 'NGUYỄN VĂN A',
                            'confidence' => 0.95,
                        ],
                    ],
                ],
                'structured' => [
                    'status' => 'completed',
                    'document' => [
                        'document_type' => 'citizen_identity_card',
                        'normalized_value' => 'Căn cước công dân / Citizen Identity Card',
                    ],
                    'fields' => [
                        'full_name' => [
                            'normalized_value' => 'Nguyễn Văn A',
                            'confidence' => 0.95,
                        ],
                        'cccd_number' => [
                            'normalized_value' => '012345678901',
                            'confidence' => 0.95,
                        ],
                    ],
                ],
            ],
            'face_crop' => [
                'media_type' => 'image/jpeg',
                'base64' => base64_encode('face-crop-jpeg-content'),
            ],
        ];
    }
}
