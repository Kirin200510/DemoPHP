<?php

namespace App\Http\Controllers;

use App\Exceptions\AiEngineResponseException;
use App\Exceptions\AiEngineUnavailableException;
use App\Exceptions\InvalidDocumentImageException;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\AuditLog;
use App\Models\ImageDocument;
use App\Services\AiImageProcessor;
use App\Services\EncryptedDocumentStorage;
use App\Services\ProcessingFailureMonitor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentController extends Controller
{
    /** @var list<string> */
    private const MANUALLY_ADDABLE_CCCD_FIELDS = [
        'cccd_number',
        'personal_identification_number',
        'full_name',
        'date_of_birth',
        'sex',
        'nationality',
        'place_of_origin',
        'residence',
        'place_of_birth',
        'personal_identification',
        'date_month_year',
        'issue_date',
        'expiry_date',
    ];

    public function create(): View
    {
        $user = request()->user();
        $recentDocumentsQuery = ImageDocument::query()
            ->whereNull('merged_into_document_id')
            ->latest('id')
            ->limit(6);

        if (! $user->hasAnyRole(['admin', 'processor', 'reviewer'])) {
            $recentDocumentsQuery
                ->where('user_id', $user->id)
                ->whereIn('status', [
                    ImageDocument::STATUS_PROCESSING,
                    ImageDocument::STATUS_COMPLETED,
                    ImageDocument::STATUS_FAILED,
                    ImageDocument::STATUS_PENDING_REVIEW,
                    ImageDocument::STATUS_VERIFIED,
                    ImageDocument::STATUS_RESUBMISSION_REQUIRED,
                ]);
        } elseif ($user->hasRole('admin')) {
            $recentDocumentsQuery->whereIn('status', [
                ImageDocument::STATUS_COMPLETED,
                ImageDocument::STATUS_PENDING_REVIEW,
                ImageDocument::STATUS_RESUBMISSION_REQUIRED,
                ImageDocument::STATUS_VERIFIED,
            ]);
        } else {
            $recentDocumentsQuery->where('status', ImageDocument::STATUS_PENDING_REVIEW);
        }

        return view('documents.create', [
            'recentDocuments' => $recentDocumentsQuery->get(),
        ]);
    }

    public function store(
        StoreDocumentRequest $request,
        AiImageProcessor $imageProcessor,
        EncryptedDocumentStorage $documentStorage,
        ProcessingFailureMonitor $failureMonitor,
    ): RedirectResponse {
        $images = $request->file('images');
        $images = is_array($images) ? $images : [$request->file('image')];
        $processedDocuments = [];
        $failedDocuments = [];
        $pairingResult = null;
        $documents = [];
        $lastSystemFailure = null;

        $images = array_values(array_filter($images, static fn ($image): bool => $image instanceof UploadedFile));

        foreach ($images as $index => $image) {
            $documents[$index] = $this->createUploadedDocument($image, $request, $documentStorage);
        }

        if (count($images) > 1) {
            $outcomes = $imageProcessor->processBatch($images);
        } else {
            try {
                $outcomes = [$imageProcessor->process($images[0])];
            } catch (Throwable $exception) {
                $outcomes = [$exception];
            }
        }

        foreach ($documents as $index => $document) {
            [$document, $exception] = $this->storeProcessingOutcome(
                $document,
                $outcomes[$index],
                $documentStorage,
            );

            if ($exception === null) {
                $processedDocuments[] = $document;
            } else {
                $failedDocuments[] = [
                    'document' => $document,
                    'message' => $this->processingFailureMessage($exception),
                ];

                if ($this->failureCategory($exception) !== 'invalid_image') {
                    $lastSystemFailure = [$document, $this->failureCategory($exception)];
                }
            }
        }

        if ($lastSystemFailure !== null) {
            $failureMonitor->recordSystemFailure(...$lastSystemFailure);
        }

        if (count($images) === 2 && count($processedDocuments) === 2) {
            try {
                $pairingResult = $imageProcessor->matchDocumentSides([
                    $processedDocuments[0]->ocr_structured_data,
                    $processedDocuments[1]->ocr_structured_data,
                ]);
            } catch (Throwable $exception) {
                $pairingResult = [
                    'status' => 'needs_review',
                    'reason' => 'side_matching_unavailable',
                    'error' => [
                        'code' => 'ERR_PAIR_SERVICE_UNAVAILABLE',
                        'message' => 'Dịch vụ đối chiếu hai mặt chưa khả dụng. OCR từng ảnh vẫn được lưu; bạn có thể yêu cầu nhân viên kiểm tra.',
                    ],
                ];

                Log::warning('Document side matching was unavailable.', [
                    'document_ids' => array_map(
                        static fn (ImageDocument $document): int => $document->id,
                        $processedDocuments,
                    ),
                    'exception' => $exception,
                ]);
            }

            $pairingResult['document_ids'] = array_map(
                static fn (ImageDocument $document): int => $document->id,
                $processedDocuments,
            );

            if ($pairingResult['status'] === 'matched'
                && is_int($pairingResult['front_input_index'] ?? null)
                && is_int($pairingResult['back_input_index'] ?? null)
                && is_array($pairingResult['paired_normalized_ocr'] ?? null)) {
                $frontDocument = $processedDocuments[$pairingResult['front_input_index']];
                $backDocument = $processedDocuments[$pairingResult['back_input_index']];
                $pairedNormalizedOcr = $pairingResult['paired_normalized_ocr'];
                $pairedNormalizedOcr['all_ocr_lines'] ??= $this->pairedOcrLines(
                    $frontDocument->ocr_raw_data ?? [],
                    $backDocument->ocr_raw_data ?? [],
                );
                $pairingResult['canonical_document_id'] = $frontDocument->id;
                $pairingResult['merged_document_id'] = $backDocument->id;
                $pairedNormalizedOcr['sides']['front']['source_document_id'] = $frontDocument->id;
                $pairedNormalizedOcr['sides']['back']['source_document_id'] = $backDocument->id;

                $frontStructuredOcr = $frontDocument->ocr_structured_data ?? [];
                $frontStructuredOcr['individual_normalized_ocr'] = [
                    'document' => $frontStructuredOcr['document'] ?? null,
                    'fields' => $frontStructuredOcr['fields'] ?? [],
                    'verification' => $frontStructuredOcr['verification'] ?? null,
                ];
                $frontStructuredOcr['pairing'] = $pairingResult;
                $frontStructuredOcr['paired_normalized_ocr'] = $pairedNormalizedOcr;
                $frontStructuredOcr['document'] = $pairedNormalizedOcr['document'] ?? $frontStructuredOcr['document'] ?? null;
                $frontStructuredOcr['fields'] = $pairedNormalizedOcr['fields'] ?? $frontStructuredOcr['fields'] ?? [];
                $frontStructuredOcr['document_sides'] = [
                    'front_document_id' => $frontDocument->id,
                    'back_document_id' => $backDocument->id,
                ];

                DB::transaction(function () use ($frontDocument, $frontStructuredOcr, $backDocument): void {
                    $frontDocument->update(['ocr_structured_data' => $frontStructuredOcr]);
                    $backDocument->update(['merged_into_document_id' => $frontDocument->id]);
                });
            } else {
                foreach ($processedDocuments as $processedDocument) {
                    $structuredOcr = $processedDocument->ocr_structured_data ?? [];
                    $structuredOcr['pairing'] = $pairingResult;
                    $processedDocument->update(['ocr_structured_data' => $structuredOcr]);
                }
            }
        }

        if (count($images) === 1 && $processedDocuments === []) {
            $failedDocument = $failedDocuments[0]['document'];

            return redirect()
                ->route('documents.show', $failedDocument)
                ->withErrors(['processing' => $failedDocuments[0]['message']]);
        }

        if (count($images) === 1) {
            return redirect()
                ->route('documents.show', $processedDocuments[0])
                ->with('success', 'Hình ảnh và dữ liệu OCR đã được xử lý, chuẩn hóa và lưu thành công.');
        }

        $message = sprintf(
            'Đã xử lý độc lập %d/%d hình ảnh. Bạn có thể mở từng hồ sơ để xem kết quả OCR.',
            count($processedDocuments),
            count($images),
        );

        if (($pairingResult['status'] ?? null) === 'matched') {
            $message .= ' Hai mặt CCCD đã khớp và dữ liệu đã được gộp.';
        } elseif ($pairingResult !== null) {
            $message .= ' '.data_get($pairingResult, 'error.message', 'Chưa đủ bằng chứng đối chiếu hai mặt. Hai ảnh chưa được gộp; hãy chọn lại ảnh hoặc yêu cầu nhân viên kiểm tra.');
        }
        $redirect = redirect()->route('documents.create');

        if (($pairingResult['status'] ?? null) === 'not_matched') {
            $redirect->withErrors(['pairing' => $message]);
        } else {
            $redirect->with($pairingResult !== null && $pairingResult['status'] !== 'matched' ? 'warning' : 'success', $message);
        }

        if ($failedDocuments !== []) {
            $failureMessages = array_values(array_unique(array_column($failedDocuments, 'message')));
            $failureMessage = count($failureMessages) === 1
                ? $failureMessages[0]
                : 'Một số hình ảnh không thể xử lý. Hãy mở từng hồ sơ lỗi để xem chi tiết.';

            $redirect->withErrors([
                'processing' => sprintf(
                    '%d hình ảnh xử lý thất bại. %s',
                    count($failedDocuments),
                    $failureMessage,
                ),
            ]);
        }

        return $redirect;
    }

    private function createUploadedDocument(
        UploadedFile $image,
        StoreDocumentRequest $request,
        EncryptedDocumentStorage $documentStorage,
    ): ImageDocument {
        $originalPath = $documentStorage->storeUploadedFile($image, 'documents/originals');
        $document = ImageDocument::query()->create([
            'user_id' => $request->user()->id,
            'original_path' => $originalPath,
            'storage_encrypted' => true,
            'expires_at' => now()->addDays(max(1, (int) config('documents.retention_days'))),
            'status' => ImageDocument::STATUS_PROCESSING,
        ]);
        $this->recordAudit('document.uploaded', $document);

        return $document;
    }

    /**
     * @param  array<string, mixed>|Throwable  $processingResult
     * @return array{0: ImageDocument, 1: Throwable|null}
     */
    private function storeProcessingOutcome(
        ImageDocument $document,
        array|Throwable $processingResult,
        EncryptedDocumentStorage $documentStorage,
    ): array {
        try {
            if ($processingResult instanceof Throwable) {
                throw $processingResult;
            }

            $processedPath = 'documents/processed/'.Str::uuid().'.jpg';
            $faceCropPath = null;
            $documentStorage->put($processedPath, $processingResult['processed_image']);

            if ($processingResult['face_crop_image'] !== null) {
                $faceCropPath = 'documents/faces/'.Str::uuid().'.jpg';
                $documentStorage->put($faceCropPath, $processingResult['face_crop_image']);
            }

            $structuredOcr = $processingResult['structured_ocr'];

            if ($faceCropPath !== null) {
                $structuredOcr['face_crop']['stored_path'] = $faceCropPath;
            }

            $document->update([
                'processed_path' => $processedPath,
                'ocr_raw_data' => $processingResult['raw_ocr'],
                'ocr_structured_data' => $structuredOcr,
                'status' => ImageDocument::STATUS_COMPLETED,
            ]);
            $this->recordAudit('document.processed', $document);

            return [$document, null];
        } catch (Throwable $exception) {
            $document->update(['status' => ImageDocument::STATUS_FAILED]);
            $failureCategory = $this->failureCategory($exception);
            $this->recordAudit('document.processing_failed', $document, [
                'exception' => $exception::class,
                'category' => $failureCategory,
            ]);

            Log::error('Document image preprocessing failed.', [
                'document_id' => $document->id,
                'exception' => $exception,
                'category' => $failureCategory,
            ]);

            return [$document, $exception];
        }
    }

    public function show(ImageDocument $document): View|RedirectResponse
    {
        if ($document->merged_into_document_id !== null) {
            return redirect()
                ->route('documents.show', $document->merged_into_document_id)
                ->with('success', 'Đây là mặt phụ của hồ sơ đã ghép. Hệ thống đã chuyển bạn đến hồ sơ tổng hợp.');
        }

        $document->load('mergedDocumentSides');

        return view('documents.show', ['document' => $document]);
    }

    public function original(ImageDocument $document, EncryptedDocumentStorage $documentStorage): StreamedResponse
    {
        $this->recordAudit('document.original_viewed', $document);

        return $this->imageResponse($document, $document->original_path, $documentStorage);
    }

    public function processed(ImageDocument $document, EncryptedDocumentStorage $documentStorage): StreamedResponse
    {
        if ($document->processed_path === null) {
            abort(404);
        }

        $this->recordAudit('document.processed_viewed', $document);

        return $this->imageResponse($document, $document->processed_path, $documentStorage);
    }

    public function faceCrop(ImageDocument $document, EncryptedDocumentStorage $documentStorage): StreamedResponse
    {
        $faceCropPath = data_get($document->ocr_structured_data, 'face_crop.stored_path');

        if (! is_string($faceCropPath) || ! str_starts_with($faceCropPath, 'documents/faces/')) {
            abort(404);
        }

        $this->recordAudit('document.face_crop_viewed', $document);

        return $this->imageResponse($document, $faceCropPath, $documentStorage);
    }

    public function updateStructuredOcr(Request $request, ImageDocument $document): RedirectResponse
    {
        Gate::authorize('updateStructuredOcr', $document);

        $validated = $request->validate([
            'fields' => ['required', 'array'],
            'fields.*' => ['nullable', 'string', 'max:2000'],
        ]);
        $structuredData = $document->ocr_structured_data ?? [];
        $fields = $structuredData['fields'] ?? [];
        $pairedFields = data_get($structuredData, 'paired_normalized_ocr.fields');
        $editedAt = now()->toIso8601String();
        $isCitizenIdentityCard = data_get($structuredData, 'document.document_type') === 'citizen_identity_card'
            || data_get($structuredData, 'paired_normalized_ocr.document.document_type') === 'citizen_identity_card';

        foreach ($validated['fields'] as $fieldName => $value) {
            $canAddCccdField = $isCitizenIdentityCard
                && in_array($fieldName, self::MANUALLY_ADDABLE_CCCD_FIELDS, true);

            if ((array_key_exists($fieldName, $fields) && is_array($fields[$fieldName])) || $canAddCccdField) {
                $fields[$fieldName] = $this->manuallyUpdatedField(
                    is_array($fields[$fieldName] ?? null) ? $fields[$fieldName] : [],
                    $fieldName,
                    trim((string) $value),
                    $request->user()->id,
                    $editedAt,
                );
            }

            if (is_array($pairedFields)
                && ((array_key_exists($fieldName, $pairedFields) && is_array($pairedFields[$fieldName])) || $canAddCccdField)) {
                $pairedFields[$fieldName] = $this->manuallyUpdatedField(
                    is_array($pairedFields[$fieldName] ?? null) ? $pairedFields[$fieldName] : [],
                    $fieldName,
                    trim((string) $value),
                    $request->user()->id,
                    $editedAt,
                );
            }
        }

        $structuredData['fields'] = $fields;

        if (is_array($pairedFields)) {
            $structuredData['paired_normalized_ocr']['fields'] = $pairedFields;
        }

        $document->update(['ocr_structured_data' => $structuredData]);
        $this->recordAudit('document.structured_ocr_updated', $document, [
            'fields' => array_keys($validated['fields']),
        ]);

        return back()->with('success', 'Dữ liệu OCR chuẩn hóa đã được cập nhật.');
    }

    public function submitForReview(ImageDocument $document): RedirectResponse
    {
        Gate::authorize('submitForReview', $document);
        $attributes = [
            'status' => ImageDocument::STATUS_PENDING_REVIEW,
            'review_notes' => null,
        ];
        $document->update($attributes);
        $document->mergedDocumentSides()->update($attributes);
        $this->recordAudit('document.submitted_for_review', $document);

        return back()->with('success', 'Đã gửi yêu cầu xác thực hồ sơ cho nhân viên xử lý.');
    }

    public function verify(Request $request, ImageDocument $document): RedirectResponse
    {
        Gate::authorize('review', $document);
        $attributes = [
            'status' => ImageDocument::STATUS_VERIFIED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => null,
        ];
        $document->update($attributes);
        $document->mergedDocumentSides()->update($attributes);
        $this->recordAudit('document.verified', $document);

        return redirect()
            ->route('documents.create')
            ->with('success', 'Hồ sơ đã được xác thực chính xác và chuyển khỏi hàng đợi.');
    }

    public function requestResubmission(Request $request, ImageDocument $document): RedirectResponse
    {
        Gate::authorize('review', $document);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $attributes = [
            'status' => ImageDocument::STATUS_RESUBMISSION_REQUIRED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $validated['reason'],
        ];
        $document->update($attributes);
        $document->mergedDocumentSides()->update($attributes);
        $this->recordAudit('document.resubmission_requested', $document);

        return redirect()
            ->route('documents.create')
            ->with('success', 'Đã yêu cầu khách hàng gửi lại thông tin và chuyển hồ sơ khỏi hàng đợi.');
    }

    private function imageResponse(
        ImageDocument $document,
        string $path,
        EncryptedDocumentStorage $documentStorage,
    ): StreamedResponse {
        if ($document->purged_at !== null) {
            abort(404);
        }

        try {
            $contents = $documentStorage->get($document, $path);
        } catch (RuntimeException $exception) {
            Log::warning('Unable to retrieve protected document image.', [
                'document_id' => $document->id,
                'exception' => $exception::class,
            ]);

            abort(404);
        }

        return response()->stream(static function () use ($contents): void {
            echo $contents;
        }, 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $this->imageMediaType($path),
        ]);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function manuallyUpdatedField(
        array $field,
        string $fieldName,
        string $value,
        int $userId,
        string $editedAt,
    ): array {
        $field['label'] ??= str($fieldName)->replace('_', ' ')->title()->toString();
        $field['normalized_value'] = $value;
        $field['extraction_status'] = 'manually_edited';
        $field['edited_by'] = $userId;
        $field['edited_at'] = $editedAt;

        return $field;
    }

    /**
     * Preserve every line returned by OCR when a front/back dossier is merged.
     *
     * @param  array<string, mixed>  $frontRawOcr
     * @param  array<string, mixed>  $backRawOcr
     * @return list<array<string, mixed>>
     */
    private function pairedOcrLines(array $frontRawOcr, array $backRawOcr): array
    {
        $pairedLines = [];

        foreach ([
            'front' => data_get($frontRawOcr, 'lines', []),
            'back' => data_get($backRawOcr, 'lines', []),
        ] as $side => $lines) {
            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $line['source_side'] = $side;
                $pairedLines[] = $line;
            }
        }

        return $pairedLines;
    }

    private function imageMediaType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordAudit(string $action, ?ImageDocument $document = null, array $metadata = []): void
    {
        AuditLog::query()->create([
            'user_id' => request()->user()?->id,
            'action' => $action,
            'auditable_type' => $document?->getMorphClass(),
            'auditable_id' => $document?->getKey(),
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    private function failureCategory(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof InvalidDocumentImageException => 'invalid_image',
            $exception instanceof AiEngineUnavailableException => 'ai_engine_unavailable',
            $exception instanceof AiEngineResponseException => 'ai_engine_invalid_response',
            default => 'application_error',
        };
    }

    private function processingFailureMessage(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof InvalidDocumentImageException => $exception->getMessage(),
            $exception instanceof AiEngineUnavailableException => 'Dịch vụ AI đang tạm thời không phản hồi. Hệ thống đã tự thử lại, vui lòng thử lại sau.',
            $exception instanceof AiEngineResponseException => 'Dịch vụ AI trả về kết quả không hợp lệ. Vui lòng thử lại sau.',
            default => 'Hệ thống không thể xử lý hình ảnh lúc này. Vui lòng thử lại sau.',
        };
    }
}
