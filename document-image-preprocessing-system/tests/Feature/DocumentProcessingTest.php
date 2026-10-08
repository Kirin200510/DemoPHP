<?php

namespace Tests\Feature;

use App\Models\ImageDocument;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentProcessingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_upload_page_displays_the_processing_form(): void
    {
        $response = $this->get(route('documents.create'));

        $response
            ->assertOk()
            ->assertSee('Xử lý hình ảnh')
            ->assertSee('name="image"', false);
    }

    public function test_valid_image_is_processed_stored_and_recorded(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        Http::fake([
            'http://ai-engine.test/preprocess' => Http::response(
                'processed-jpeg-content',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $response = $this->post(route('documents.store'), [
            'image' => $this->fakePng('cccd.png'),
        ]);
        $document = ImageDocument::query()->sole();

        $response
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHas('success', 'Hình ảnh đã được xử lý và lưu thành công.');
        $this->assertSame(ImageDocument::STATUS_COMPLETED, $document->status);
        $this->assertNotNull($document->processed_path);
        Storage::disk('local')->assertExists($document->original_path);
        Storage::disk('local')->assertExists($document->processed_path);
        $this->assertSame(
            'processed-jpeg-content',
            Storage::disk('local')->get($document->processed_path),
        );
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ai-engine.test/preprocess'
            && $request->hasFile('file', filename: 'cccd.png'));
    }

    public function test_ai_engine_failure_marks_document_as_failed_without_processed_image(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        Http::fake([
            'http://ai-engine.test/preprocess' => Http::response(
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
}
