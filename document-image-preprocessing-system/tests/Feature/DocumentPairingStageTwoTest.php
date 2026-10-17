<?php

namespace Tests\Feature;

use App\Exceptions\AiEngineResponseException;
use App\Models\AuditLog;
use App\Models\ImageDocument;
use App\Models\User;
use App\Services\AiImageProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentPairingStageTwoTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_mismatch_400_preserves_independent_ocr_and_displays_an_error_without_merging(): void
    {
        $this->actingAsCustomer();
        $this->fakeAiResponses([
            'status' => 'not_matched',
            'reason' => 'cccd_number_does_not_match_mrz_positions',
            'error' => ['code' => 'ERR_PAIR_MISMATCH', 'message' => '<script>unsafe</script>'],
            'checks' => ['mrz_positions_16_27_matches_front_number' => false],
        ], 400);

        $response = $this->post(route('documents.store'), ['images' => $this->images()]);

        $response->assertRedirect(route('documents.create'))
            ->assertSessionHasErrors('pairing')
            ->assertSessionMissing('success');
        $documents = ImageDocument::query()->get();
        $this->assertCount(2, $documents);
        foreach ($documents as $document) {
            $this->assertSame(ImageDocument::STATUS_COMPLETED, $document->status);
            $this->assertNull($document->merged_into_document_id);
            $this->assertArrayNotHasKey('paired_normalized_ocr', $document->ocr_structured_data);
            $this->assertSame('ERR_PAIR_MISMATCH', data_get($document->ocr_structured_data, 'pairing.error.code'));
            $this->assertSame('ORIGINAL OCR', $document->ocr_raw_data['full_text']);
            Storage::disk('local')->assertExists($document->original_path);
            Storage::disk('local')->assertExists($document->processed_path);
        }
        $this->assertSame(0, AuditLog::query()->where('action', 'document.processing_failed')->count());
        $this->get(route('documents.show', $documents->first()))
            ->assertSee('Không khớp 2 mặt')
            ->assertSee('không thuộc cùng một giấy tờ')
            ->assertSee('Yêu cầu nhân viên kiểm tra thủ công')
            ->assertDontSee('<script>unsafe</script>', false);
        Http::assertSentCount(2);
    }

    public function test_insufficient_evidence_422_displays_a_warning_instead_of_ocr_failure(): void
    {
        $this->actingAsCustomer();
        $this->fakeAiResponses([
            'status' => 'needs_review',
            'reason' => 'positioned_mrz_identifier_not_available',
            'error' => ['code' => 'ERR_PAIR_INSUFFICIENT_EVIDENCE', 'message' => 'Unknown'],
        ], 422);

        $response = $this->post(route('documents.store'), ['images' => $this->images()]);

        $response->assertSessionHas('warning')->assertSessionMissing('success')->assertSessionDoesntHaveErrors();
        $document = ImageDocument::query()->firstOrFail();
        $this->assertSame('ERR_PAIR_INSUFFICIENT_EVIDENCE', data_get($document->ocr_structured_data, 'pairing.error.code'));
        $this->assertSame(ImageDocument::STATUS_COMPLETED, $document->status);
        $this->get(route('documents.show', $document))
            ->assertSee('Chưa đủ bằng chứng / cần kiểm tra')
            ->assertSee('Chưa đủ thông tin để đối chiếu hai mặt');
    }

    public function test_pairing_outage_is_distinguished_from_a_business_mismatch(): void
    {
        $this->actingAsCustomer();
        $this->fakeAiResponses(['detail' => 'unavailable'], 503);

        $response = $this->post(route('documents.store'), ['images' => $this->images()]);

        $response->assertSessionHas('warning')->assertSessionMissing('success');
        $document = ImageDocument::query()->firstOrFail();
        $this->assertSame(ImageDocument::STATUS_COMPLETED, $document->status);
        $this->assertSame('ERR_PAIR_SERVICE_UNAVAILABLE', data_get($document->ocr_structured_data, 'pairing.error.code'));
        $this->assertNull($document->merged_into_document_id);
    }

    public function test_unknown_422_payload_is_not_accepted_as_a_business_rejection(): void
    {
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        Http::fake(['http://ai-engine.test/document-sides/match' => Http::response([
            'status' => 'matched', 'error' => ['code' => 'ERR_PAIR_MISMATCH'],
        ], 422)]);

        $this->expectException(AiEngineResponseException::class);

        app(AiImageProcessor::class)->matchDocumentSides([[], []]);
    }

    public function test_barcode_match_in_reverse_order_shows_merged_data_and_keeps_each_raw_result(): void
    {
        $this->actingAsCustomer();
        $this->fakeAiResponses([
            'status' => 'matched', 'matching_method' => 'barcode',
            'front_input_index' => 1, 'back_input_index' => 0,
            'paired_normalized_ocr' => [
                'document' => ['document_type' => 'citizen_identity_card'],
                'fields' => ['residence' => ['label' => 'Nơi cư trú/Place of residence', 'normalized_value' => 'Địa chỉ từ mã vạch', 'source_side' => 'back', 'source_type' => 'barcode']],
            ],
        ], 200);

        $response = $this->post(route('documents.store'), ['images' => $this->images()]);

        $response->assertSessionHas('success')->assertSessionDoesntHaveErrors();
        $documents = ImageDocument::query()->orderBy('id')->get();
        $front = $documents->last();
        $back = $documents->first();
        $this->assertNull($front->merged_into_document_id);
        $this->assertSame($front->id, $back->merged_into_document_id);
        $this->assertSame('ORIGINAL OCR', $front->ocr_raw_data['full_text']);
        $this->assertSame('ORIGINAL OCR', $back->ocr_raw_data['full_text']);
        $this->get(route('documents.show', $front))
            ->assertSee('Phương pháp đối chiếu: Mã vạch')
            ->assertSee('Địa chỉ từ mã vạch');
        $this->get(route('documents.show', $back))->assertRedirect(route('documents.show', $front));
    }

    public function test_empty_success_payload_cannot_merge_or_hide_a_document(): void
    {
        $this->actingAsCustomer();
        $this->fakeAiResponses([
            'status' => 'matched', 'front_input_index' => 0, 'back_input_index' => 1,
            'paired_normalized_ocr' => [],
        ], 200);

        $response = $this->post(route('documents.store'), ['images' => $this->images()]);

        $response->assertSessionHas('warning')->assertSessionMissing('success');
        $documents = ImageDocument::query()->get();
        $this->assertCount(2, $documents);
        foreach ($documents as $document) {
            $this->assertNull($document->merged_into_document_id);
            $this->assertSame(ImageDocument::STATUS_COMPLETED, $document->status);
        }
    }

    public function test_unknown_barcode_structure_is_visible_without_exposing_raw_payload(): void
    {
        $user = $this->actingAsCustomer();
        Storage::fake('local');
        $document = ImageDocument::factory()->create([
            'user_id' => $user->id,
            'ocr_structured_data' => [
                'document' => ['document_type' => 'citizen_identity_card'], 'fields' => [],
                'barcodes' => ['records' => [[
                    'format' => 'PDF417', 'raw_text' => 'SENSITIVE BARCODE PAYLOAD',
                    'parsed' => ['status' => 'unsupported', 'fields' => []],
                ]]],
            ],
        ]);

        $response = $this->get(route('documents.show', $document));

        $response->assertSee('Đã đọc mã vạch nhưng chưa hỗ trợ cấu trúc nội dung')
            ->assertDontSee('SENSITIVE BARCODE PAYLOAD');
    }

    private function actingAsCustomer(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('customer');
        $this->actingAs($user);

        return $user;
    }

    /** @return list<UploadedFile> */
    private function images(): array
    {
        $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=', true);

        return [
            UploadedFile::fake()->createWithContent('first.png', $content),
            UploadedFile::fake()->createWithContent('second.png', $content),
        ];
    }

    /** @param array<string, mixed> $pairing */
    private function fakeAiResponses(array $pairing, int $status): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        $processed = [
            'status' => 'completed',
            'processed_image' => ['media_type' => 'image/jpeg', 'base64' => base64_encode('processed image')],
            'ocr' => [
                'raw' => ['full_text' => 'ORIGINAL OCR', 'lines' => [['index' => 0, 'text' => 'ORIGINAL OCR']]],
                'structured' => ['document' => ['document_type' => 'citizen_identity_card'], 'fields' => []],
            ],
        ];
        Http::fake([
            'http://ai-engine.test/process-batch' => Http::response(['documents' => [$processed, $processed]]),
            'http://ai-engine.test/document-sides/match' => Http::response($pairing, $status),
        ]);
    }
}
