<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ImageDocument;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\EncryptedDocumentStorage;
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
            ->assertSee('JPG, JPEG, PNG, WEBP · tối đa 10 MB')
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
        $this->assertNotSame('processed-jpeg-content', Storage::disk('local')->get($document->processed_path));
        $this->assertNotSame('face-crop-jpeg-content', Storage::disk('local')->get($faceCropPath));
        $this->assertTrue($document->storage_encrypted);
        $this->assertNotNull($document->expires_at);
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

        $this->get(route('documents.processed', $document))
            ->assertOk()
            ->assertStreamedContent('processed-jpeg-content');
    }

    public function test_multiple_images_are_processed_as_independent_documents(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        Http::fake([
            'http://ai-engine.test/process-batch' => Http::response([
                'documents' => [$this->successfulAiResponse(), $this->successfulAiResponse()],
            ]),
            'http://ai-engine.test/document-sides/match' => Http::response([
                'status' => 'needs_review',
                'reason' => 'could_not_identify_one_front_and_one_back_side',
                'paired_normalized_ocr' => null,
            ]),
        ]);

        $response = $this->post(route('documents.store'), [
            'images' => [
                $this->fakePng('cccd-front.png'),
                $this->fakePng('cccd-back.png'),
            ],
        ]);

        $documents = ImageDocument::query()->orderBy('id')->get();

        $response
            ->assertRedirect(route('documents.create'))
            ->assertSessionMissing('success')
            ->assertSessionHas('warning', 'Đã xử lý độc lập 2/2 hình ảnh. Bạn có thể mở từng hồ sơ để xem kết quả OCR. Chưa đủ bằng chứng đối chiếu hai mặt. Hai ảnh chưa được gộp; hãy chọn lại ảnh hoặc yêu cầu nhân viên kiểm tra.');
        $this->assertCount(2, $documents);
        $this->assertTrue($documents->every(
            static fn (ImageDocument $document): bool => $document->status === ImageDocument::STATUS_COMPLETED,
        ));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://ai-engine.test/process-batch'
            && $request->hasFile('files', filename: 'cccd-front.png')
            && $request->hasFile('files', filename: 'cccd-back.png'));
    }

    public function test_matched_cccd_sides_show_the_complete_paired_normalized_data_on_each_document(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        Http::fake([
            'http://ai-engine.test/process-batch' => Http::response([
                'documents' => [$this->successfulAiResponse(), $this->successfulAiResponse()],
            ]),
            'http://ai-engine.test/document-sides/match' => Http::response([
                'status' => 'matched',
                'front_input_index' => 0,
                'back_input_index' => 1,
                'paired_normalized_ocr' => [
                    'document' => [
                        'document_type' => 'citizen_identity_card',
                        'normalized_value' => 'Căn cước công dân / Citizen Identity Card',
                    ],
                    'fields' => [
                        'cccd_number' => [
                            'label' => 'Số/No',
                            'normalized_value' => '012345678901',
                            'source_side' => 'front',
                        ],
                        'full_name' => [
                            'label' => 'Họ và tên/Full name',
                            'normalized_value' => 'Nguyễn Văn A',
                            'source_side' => 'front',
                        ],
                        'place_of_birth' => [
                            'label' => 'Nơi đăng ký khai sinh/Place of birth',
                            'normalized_value' => 'Thông tin từ mặt sau',
                            'source_side' => 'back',
                        ],
                        'issue_date' => [
                            'label' => 'Ngày, tháng, năm cấp/Date of issue',
                            'normalized_value' => '01/01/2025',
                            'source_side' => 'back',
                        ],
                    ],
                    'unmapped_raw_lines' => [
                        [
                            'index' => 10,
                            'text' => 'Nội dung OCR mặt sau chưa gán trường',
                            'confidence' => 0.8,
                            'source_side' => 'back',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->post(route('documents.store'), [
            'images' => [
                $this->fakePng('cccd-front.png'),
                $this->fakePng('cccd-back.png'),
            ],
        ]);

        $documents = ImageDocument::query()->orderBy('id')->get();
        $canonicalDocument = $documents->first();
        $mergedSideDocument = $documents->last();

        $this->assertCount(2, $documents);
        $structuredData = $canonicalDocument->ocr_structured_data;
        $this->assertSame(
            'Thông tin từ mặt sau',
            $structuredData['fields']['place_of_birth']['normalized_value'],
        );
        $this->assertSame(
            'Nguyễn Văn A',
            $structuredData['individual_normalized_ocr']['fields']['full_name']['normalized_value'],
        );
        $this->assertSame(
            $canonicalDocument->id,
            $structuredData['pairing']['canonical_document_id'],
        );
        $this->assertNull($canonicalDocument->merged_into_document_id);
        $this->assertSame($canonicalDocument->id, $mergedSideDocument->merged_into_document_id);
        $this->assertSame(
            ['front', 'back'],
            array_column(
                $structuredData['paired_normalized_ocr']['all_ocr_lines'],
                'source_side',
            ),
        );

        $this->get(route('documents.show', $canonicalDocument))
            ->assertOk()
            ->assertSee('Nơi đăng ký khai sinh/Place of birth')
            ->assertSee('Thông tin từ mặt sau')
            ->assertSee('Ngày, tháng, năm cấp/Date of issue')
            ->assertSee('Nội dung OCR mặt sau chưa gán trường')
            ->assertSee('Nguồn: Mặt sau')
            ->assertSee('Toàn bộ nội dung OCR đọc được')
            ->assertSee('Ảnh sau xử lý · Mặt sau');
        $this->get(route('documents.show', $mergedSideDocument))
            ->assertRedirect(route('documents.show', $canonicalDocument));

        $this->put(route('documents.ocr.update', $canonicalDocument), [
            'fields' => ['place_of_birth' => 'Thông tin đã chỉnh sửa'],
        ])->assertRedirect();

        $canonicalDocument->refresh();
        $updatedStructuredData = $canonicalDocument->ocr_structured_data;
        $this->assertSame(
            'Thông tin đã chỉnh sửa',
            $updatedStructuredData['fields']['place_of_birth']['normalized_value'],
        );
        $this->assertSame(
            'Thông tin đã chỉnh sửa',
            $updatedStructuredData['paired_normalized_ocr']['fields']['place_of_birth']['normalized_value'],
        );
        $this->get(route('documents.show', $canonicalDocument))
            ->assertOk()
            ->assertSee('Thông tin đã chỉnh sửa');

        $this->put(route('documents.ocr.update', $canonicalDocument), [
            'fields' => ['place_of_origin' => 'Trường được nhập thủ công'],
        ])->assertRedirect();

        $canonicalDocument->refresh();
        $updatedStructuredData = $canonicalDocument->ocr_structured_data;
        $this->assertSame(
            'Trường được nhập thủ công',
            $updatedStructuredData['fields']['place_of_origin']['normalized_value'],
        );
        $this->assertSame(
            'Trường được nhập thủ công',
            $updatedStructuredData['paired_normalized_ocr']['fields']['place_of_origin']['normalized_value'],
        );
        $this->get(route('documents.show', $canonicalDocument))
            ->assertOk()
            ->assertSee('Trường được nhập thủ công');
    }

    public function test_multiple_images_expose_ai_engine_unavailable_message(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'services.ai_engine.url' => 'http://ai-engine.test',
            'services.ai_engine.retry_attempts' => 1,
            'services.ai_engine.retry_delay_milliseconds' => 0,
        ]);
        Http::fake([
            'http://ai-engine.test/process-batch' => Http::response(['detail' => 'Pipeline failed'], 500),
        ]);

        $response = $this->post(route('documents.store'), [
            'images' => [
                $this->fakePng('cccd-front.png'),
                $this->fakePng('cccd-back.png'),
            ],
        ]);

        $response->assertRedirect(route('documents.create'))
            ->assertSessionHasErrors([
                'processing' => '2 hình ảnh xử lý thất bại. Dịch vụ AI đang tạm thời không phản hồi. Hệ thống đã tự thử lại, vui lòng thử lại sau.',
            ]);
        $this->assertCount(2, ImageDocument::query()->where('status', ImageDocument::STATUS_FAILED)->get());
        Http::assertSentCount(1);
    }

    public function test_batch_keeps_successful_images_and_retries_only_system_failures(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'services.ai_engine.url' => 'http://ai-engine.test',
            'services.ai_engine.retry_attempts' => 2,
            'services.ai_engine.retry_delay_milliseconds' => 0,
        ]);
        SystemSetting::query()->create([
            'key' => SystemSetting::OCR_PROVIDER_KEY,
            'value' => 'fpt_ai_reader',
        ]);
        $retriedResponse = $this->successfulAiResponse();
        $retriedResponse['ocr']['raw']['full_text'] = 'Retried image';
        Http::fake([
            'http://ai-engine.test/process-batch' => Http::sequence()
                ->push(['documents' => [
                    $this->successfulAiResponse(),
                    ['status' => 'failed', 'http_status' => 422, 'detail' => ['code' => 'document_frame_not_detected']],
                    ['status' => 'failed', 'http_status' => 503, 'detail' => 'Unavailable'],
                ]])
                ->push(['documents' => [$retriedResponse]]),
        ]);

        $this->post(route('documents.store'), ['images' => [
            $this->fakePng('first.png'),
            $this->fakePng('rejected.png'),
            $this->fakePng('retry.png'),
        ]])->assertRedirect(route('documents.create'))
            ->assertSessionHasErrors('processing');

        $documents = ImageDocument::query()->orderBy('id')->get();
        $this->assertSame([
            ImageDocument::STATUS_COMPLETED,
            ImageDocument::STATUS_FAILED,
            ImageDocument::STATUS_COMPLETED,
        ], $documents->pluck('status')->all());
        $this->assertSame('Retried image', $documents[2]->ocr_raw_data['full_text']);
        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertTrue($requests[1][0]->hasFile('files', filename: 'retry.png'));
        $this->assertFalse($requests[1][0]->hasFile('files', filename: 'first.png'));
        $this->assertFalse($requests[1][0]->hasFile('files', filename: 'rejected.png'));
        $this->assertSame(['fpt_ai_reader'], $requests[1][0]->header('X-OCR-Provider'));
    }

    public function test_batch_rejects_missing_results_instead_of_assigning_them_to_the_wrong_image(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        Http::fake([
            'http://ai-engine.test/process-batch' => Http::response([
                'documents' => [$this->successfulAiResponse()],
            ]),
        ]);

        $this->post(route('documents.store'), ['images' => [
            $this->fakePng('first.png'),
            $this->fakePng('second.png'),
        ]])->assertSessionHasErrors('processing');

        $this->assertSame(2, ImageDocument::query()->where('status', ImageDocument::STATUS_FAILED)->count());
        Http::assertSentCount(1);
    }

    public function test_batch_alert_is_evaluated_after_all_successes_and_failures_are_recorded(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'services.ai_engine.url' => 'http://ai-engine.test',
            'services.ai_engine.retry_attempts' => 1,
            'services.ai_engine.alert_minimum_requests' => 1,
            'services.ai_engine.alert_failure_ratio' => 0.4,
        ]);
        Http::fake([
            'http://ai-engine.test/process-batch' => Http::response(['documents' => [
                ['status' => 'failed', 'http_status' => 503],
                $this->successfulAiResponse(),
                $this->successfulAiResponse(),
            ]]),
        ]);

        $this->post(route('documents.store'), ['images' => [
            $this->fakePng('failure-first.png'),
            $this->fakePng('success-one.png'),
            $this->fakePng('success-two.png'),
        ]])->assertSessionHasErrors('processing');

        $this->assertSame(0, AuditLog::query()->where('action', 'alert.ai_engine_failure_rate_high')->count());
    }

    public function test_selected_ocr_vendor_is_sent_to_the_ai_engine_per_request(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        SystemSetting::query()->create([
            'key' => SystemSetting::OCR_PROVIDER_KEY,
            'value' => 'fpt_ai_reader',
        ]);
        Http::fake([
            'http://ai-engine.test/process' => Http::response($this->successfulAiResponse()),
        ]);

        $this->post(route('documents.store'), [
            'image' => $this->fakePng('vendor-test.png'),
        ])->assertRedirect();

        Http::assertSent(fn (Request $request): bool => $request->header('X-OCR-Provider') === ['fpt_ai_reader']);
    }

    public function test_ai_engine_failure_marks_document_as_failed_without_processed_image(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'services.ai_engine.url' => 'http://ai-engine.test',
            'services.ai_engine.retry_attempts' => 3,
            'services.ai_engine.retry_delay_milliseconds' => 0,
        ]);
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
                'processing' => 'Dịch vụ AI đang tạm thời không phản hồi. Hệ thống đã tự thử lại, vui lòng thử lại sau.',
            ]);
        $this->assertSame(ImageDocument::STATUS_FAILED, $document->status);
        $this->assertNull($document->processed_path);
        $this->assertNull($document->ocr_raw_data);
        $this->assertNull($document->ocr_structured_data);
        Storage::disk('local')->assertExists($document->original_path);
        Http::assertSentCount(3);
    }

    public function test_ai_engine_retries_a_temporary_server_failure_then_processes_the_document(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'services.ai_engine.url' => 'http://ai-engine.test',
            'services.ai_engine.retry_attempts' => 3,
            'services.ai_engine.retry_delay_milliseconds' => 0,
        ]);
        Http::fake([
            'http://ai-engine.test/process' => Http::sequence()
                ->push(['detail' => 'Temporary failure'], 500)
                ->push($this->successfulAiResponse()),
        ]);

        $response = $this->post(route('documents.store'), [
            'image' => $this->fakePng('retry-document.png'),
        ]);
        $document = ImageDocument::query()->sole();

        $response->assertRedirect(route('documents.show', $document));
        $this->assertSame(ImageDocument::STATUS_COMPLETED, $document->status);
        Http::assertSentCount(2);
    }

    public function test_invalid_image_quality_is_not_retried_and_shows_a_specific_message(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'services.ai_engine.url' => 'http://ai-engine.test',
            'services.ai_engine.retry_attempts' => 3,
            'services.ai_engine.retry_delay_milliseconds' => 0,
        ]);
        Http::fake([
            'http://ai-engine.test/process' => Http::response([
                'detail' => [
                    'code' => 'image_too_blurry_or_low_contrast',
                ],
            ], 422),
        ]);

        $response = $this->post(route('documents.store'), [
            'image' => $this->fakePng('blurry-document.png'),
        ]);
        $document = ImageDocument::query()->sole();

        $response
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHasErrors([
                'processing' => 'Ảnh quá mờ hoặc thiếu tương phản. Vui lòng chụp lại rõ nét hơn.',
            ]);
        $this->assertSame(ImageDocument::STATUS_FAILED, $document->status);
        Http::assertSentCount(1);
    }

    public function test_missing_document_frame_is_not_retried_and_shows_a_specific_message(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'services.ai_engine.url' => 'http://ai-engine.test',
            'services.ai_engine.retry_attempts' => 3,
            'services.ai_engine.retry_delay_milliseconds' => 0,
        ]);
        Http::fake([
            'http://ai-engine.test/process' => Http::response([
                'detail' => [
                    'code' => 'document_frame_not_detected',
                ],
            ], 422),
        ]);

        $response = $this->post(route('documents.store'), [
            'image' => $this->fakePng('no-document-frame.png'),
        ]);
        $document = ImageDocument::query()->sole();

        $response
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionHasErrors([
                'processing' => 'Không nhận diện được khung giấy tờ. Vui lòng chụp đầy đủ bốn cạnh giấy tờ trên nền tương phản và thử lại.',
            ]);
        $this->assertSame(ImageDocument::STATUS_FAILED, $document->status);
        Http::assertSentCount(1);
    }

    public function test_high_system_failure_rate_creates_an_audit_alert(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config([
            'services.ai_engine.url' => 'http://ai-engine.test',
            'services.ai_engine.retry_attempts' => 1,
            'services.ai_engine.retry_delay_milliseconds' => 0,
            'services.ai_engine.alert_window_minutes' => 15,
            'services.ai_engine.alert_minimum_requests' => 1,
            'services.ai_engine.alert_failure_ratio' => 0.4,
            'services.ai_engine.alert_cooldown_minutes' => 15,
        ]);
        Http::fake([
            'http://ai-engine.test/process' => Http::response(['detail' => 'Pipeline failed'], 500),
        ]);

        $this->post(route('documents.store'), [
            'image' => $this->fakePng('engine-failure.png'),
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'alert.ai_engine_failure_rate_high',
        ]);
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

    public function test_image_smaller_than_98_kb_is_sent_to_the_ai_engine(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        Http::fake([
            'http://ai-engine.test/process' => Http::response($this->successfulAiResponse()),
        ]);

        $response = $this->post(route('documents.store'), [
            'image' => $this->fakePng('small.png', 97),
        ]);
        $document = ImageDocument::query()->sole();

        $response
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(ImageDocument::STATUS_COMPLETED, $document->status);
        Http::assertSentCount(1);
    }

    public function test_image_larger_than_10_mb_is_rejected_without_calling_ai_engine(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();

        $response = $this->from(route('documents.create'))->post(route('documents.store'), [
            'image' => $this->fakePng('large.png', 10241),
        ]);

        $response
            ->assertRedirect(route('documents.create'))
            ->assertSessionHasErrors([
                'image' => 'Dung lượng hình ảnh không được vượt quá 10 MB.',
            ]);
        $this->assertDatabaseCount('image_documents', 0);
        Http::assertNothingSent();
    }

    public function test_image_outside_output_pixel_dimensions_is_sent_to_the_ai_engine(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.ai_engine.url' => 'http://ai-engine.test']);
        Http::fake([
            'http://ai-engine.test/process' => Http::response($this->successfulAiResponse()),
        ]);

        $response = $this->post(route('documents.store'), [
            'image' => $this->fakeNarrowPng('narrow.png'),
        ]);
        $document = ImageDocument::query()->sole();

        $response
            ->assertRedirect(route('documents.show', $document))
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(ImageDocument::STATUS_COMPLETED, $document->status);
        Http::assertSentCount(1);
    }

    public function test_expired_document_data_is_purged_by_the_retention_command(): void
    {
        Storage::fake('local');
        $document = ImageDocument::factory()->create([
            'expires_at' => now()->subMinute(),
            'storage_encrypted' => false,
            'ocr_raw_data' => ['full_text' => 'Sensitive OCR'],
            'ocr_structured_data' => ['fields' => []],
        ]);
        Storage::disk('local')->put($document->original_path, 'original-content');
        Storage::disk('local')->put($document->processed_path, 'processed-content');

        $this->artisan('documents:purge-expired')
            ->expectsOutput('Purged 1 expired document record(s).')
            ->assertSuccessful();

        $document->refresh();
        Storage::disk('local')->assertMissing($document->original_path);
        $this->assertNull($document->processed_path);
        $this->assertNull($document->ocr_raw_data);
        $this->assertNull($document->ocr_structured_data);
        $this->assertNotNull($document->purged_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.data_purged',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_legacy_document_files_can_be_encrypted_without_changing_their_content(): void
    {
        Storage::fake('local');
        $document = ImageDocument::factory()->create([
            'storage_encrypted' => false,
            'original_path' => 'documents/originals/legacy.jpg',
            'processed_path' => 'documents/processed/legacy.jpg',
        ]);
        Storage::disk('local')->put($document->original_path, 'original-content');
        Storage::disk('local')->put($document->processed_path, 'processed-content');

        $this->artisan('documents:encrypt-legacy-storage')
            ->expectsOutput('Encrypted 1 legacy document record(s).')
            ->assertSuccessful();

        $document->refresh();
        $this->assertTrue($document->storage_encrypted);
        $this->assertNotSame('original-content', Storage::disk('local')->get($document->original_path));
        $this->assertSame(
            'original-content',
            app(EncryptedDocumentStorage::class)->get($document, $document->original_path),
        );
    }

    private function fakePng(string $name, int $reportedSizeInKilobytes = 100): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAV4AAAFeCAIAAABCSeBNAAAEDklEQVR4nO3UsQ0AIRDAsOf33/mokdJDYU+QKmtmPoDTfzsAeJE1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgWAMQrAEI1gAEawCCNQDBGoBgDUCwBiBYAxCsAQjWAARrAII1AMEagGANQLAGIFgDEKwBCNYABGsAgjUAwRqAYA1AsAYgbHU1BbkAbxS7AAAAAElFTkSuQmCC',
                true,
            ),
        )->size($reportedSizeInKilobytes);
    }

    private function fakeNarrowPng(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAV0AAAFeCAIAAACpfltOAAAD40lEQVR4nO3UsRHAIBDAsJD9d35azgtAIU3gymtmPoDDfzsAeI4vAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+AJQvAOULQPkCUL4AlC8A5QtA+QJQvgCULwDlC0D5AlC+ANQGks8FubNx9hkAAAAASUVORK5CYII=',
                true,
            ),
        )->size(100);
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
