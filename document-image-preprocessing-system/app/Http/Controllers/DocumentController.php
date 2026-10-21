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
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    /** @var array<string, list<string>> */
    private const MANUALLY_ADDABLE_FIELDS = [
        'citizen_identity_card' => self::MANUALLY_ADDABLE_CCCD_FIELDS,
        'driver_license' => [
            'document_number',
            'full_name',
            'date_of_birth',
            'nationality',
            'residence',
            'license_class',
            'expiry_date',
        ],
        'vehicle_registration' => [
            'owner_name',
            'vehicle_address',
            'brand',
            'model_code',
            'engine_number',
            'chassis_number',
            'paint_color',
            'operating_scope',
            'registration_plate',
            'seating_capacity',
            'expiry_date',
            'engine_power',
            'vehicle_type',
            'engine_displacement',
        ],
    ];

    public function index(Request $request): View
    {
        $user = request()->user();
        // Kiểm tra quyền truy cập của người dùng
        if (! $user->can('documents.view-own')) {
            abort(403, 'Bạn không có quyền truy cập vào trang này.');
        }
        $statuses = [
            ImageDocument::STATUS_PROCESSING,
            ImageDocument::STATUS_COMPLETED,
            ImageDocument::STATUS_FAILED,
            ImageDocument::STATUS_PENDING_REVIEW,
            ImageDocument::STATUS_VERIFIED,
            ImageDocument::STATUS_RESUBMISSION_REQUIRED,
        ];
        $status = $request->query('status', 'all');
        if ($status !== 'all' && ! in_array($status, $statuses, true)) {
            abort(404, 'Trạng thái hồ sơ không hợp lệ.');
        }
        // truy vấn
        $query = ImageDocument::query()
            ->where('user_id', $user->id)
            ->whereNull('merged_into_document_id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $documents = $query
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        // relative path for pagination links
        $documents->withPath('/my-documents');

        return view('documents.index', [
            'documents' => $documents,
            'status' => $status,
        ]);

    }

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

    // gọi các service để xử lý hình ảnh và lưu trữ kết quả, đồng thời quản lý các lỗi xảy ra trong quá trình xử lý.
    public function store(
        StoreDocumentRequest $request,
        AiImageProcessor $imageProcessor,
        EncryptedDocumentStorage $documentStorage,
        ProcessingFailureMonitor $failureMonitor,
    ): RedirectResponse {
        // Lấy hình từ request
        $images = $request->file('images');
        $images = is_array($images) ? $images : [$request->file('image')];
        // Khởi tạo các mảng để lưu trữ kết quả xử lý thành công,thất bại, kết quả đối chiéu
        $processedDocuments = [];
        $failedDocuments = [];
        $pairingResult = null;
        $documents = [];
        $lastSystemFailure = null;
        // lọc các hình ảnh được tải lên hợp lệ và đánh lại chỉ số cho từng hình ảnh
        $images = array_values(array_filter($images, static fn ($image): bool => $image instanceof UploadedFile));

        // duyệt từng phần tử để tạo dữ liệu trong bảng
        foreach ($images as $index => $image) {
            $documents[$index] = $this->createUploadedDocument($image, $request, $documentStorage);
        }
        // gọi service xử lý hình ảnh(AI-engine) theo điều kiện
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
                $invalidPairingResponse = $exception instanceof AiEngineResponseException;
                $pairingResult = [
                    'status' => 'needs_review',
                    'reason' => $invalidPairingResponse ? 'side_matching_invalid_response' : 'side_matching_unavailable',
                    'error' => [
                        'code' => $invalidPairingResponse ? 'ERR_PAIR_INVALID_RESPONSE' : 'ERR_PAIR_SERVICE_UNAVAILABLE',
                        'message' => $invalidPairingResponse
                            ? 'Dữ liệu trao đổi với dịch vụ đối chiếu hai mặt không hợp lệ. OCR từng ảnh vẫn được lưu; bạn có thể yêu cầu nhân viên kiểm tra.'
                            : 'Dịch vụ đối chiếu hai mặt chưa khả dụng. OCR từng ảnh vẫn được lưu; bạn có thể yêu cầu nhân viên kiểm tra.',
                    ],
                ];

                Log::warning('Document side matching failed.', [
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
        // lưu hình đc tải lên vào storage
        $originalPath = $documentStorage->storeUploadedFile($image, 'documents/originals');
        // tạo dòng dữ liệu trong bảng ImageDocument
        $document = ImageDocument::query()->create([
            'user_id' => $request->user()->id,
            'original_path' => $originalPath,
            'storage_encrypted' => true,
            'expires_at' => now()->addDays(max(1, (int) config('documents.retention_days'))),
            'status' => ImageDocument::STATUS_PROCESSING,
        ]);
        // ghi audit log cho hành động tải lên tài liệu
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

    public function exportReport(ImageDocument $document)
    {
        // Check policies hàm exportReport trong ImageDocumentPolicy
        Gate::authorize('exportReport', $document);
        // Lấy dữ liệu ocr
        $structuredData = $document->ocr_structured_data;
        $is_Paired = data_get($structuredData, 'pairing.status') === 'matched';
        $fields = $is_Paired
            ? data_get($structuredData, 'paired_normalized_ocr.fields')
            : data_get($structuredData, 'fields');

        // Lấy thông tin loại giấy tờ từ dữ liệu OCR
        $documentInfo = $is_Paired
            ? data_get($structuredData, 'paired_normalized_ocr.document')
            : data_get($structuredData, 'document');
        $currentTimeVN = now()->addHours(7);
        $pdf = Pdf::loadView('documents.report', [
            'document' => $document,
            'fields' => $fields,
            'documentInfo' => $documentInfo,
            'isPaired' => $is_Paired,
            'generatedAt' => $currentTimeVN,
        ])->setPaper('a4', 'portrait');

        // ghi log audit cho hành động xuất báo cáo`
        $this->recordAudit('document.report_exported', $document);

        return $pdf->download("Bao_Cao_Ho_So_{$document->id}.pdf");
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
        $fields = is_array($structuredData['fields'] ?? null) ? $structuredData['fields'] : [];
        $pairedFields = data_get($structuredData, 'paired_normalized_ocr.fields');
        $editedAt = now()->toIso8601String();
        $documentType = data_get($structuredData, 'document.document_type')
            ?? data_get($structuredData, 'paired_normalized_ocr.document.document_type');
        $manuallyAddableFields = self::MANUALLY_ADDABLE_FIELDS[$documentType] ?? [];

        foreach ($validated['fields'] as $fieldName => $value) {
            $canAddField = in_array($fieldName, $manuallyAddableFields, true);
            $existsInFields = is_array($fields[$fieldName] ?? null);
            $existsInPairedFields = is_array($pairedFields) && is_array($pairedFields[$fieldName] ?? null);

            if (! $canAddField && ! $existsInFields && ! $existsInPairedFields) {
                throw ValidationException::withMessages([
                    'fields.'.$fieldName => 'Trường dữ liệu không được hỗ trợ cập nhật cho loại giấy tờ này.',
                ]);
            }

            if ($existsInFields || $canAddField) {
                $fields[$fieldName] = $this->manuallyUpdatedField(
                    is_array($fields[$fieldName] ?? null) ? $fields[$fieldName] : [],
                    $fieldName,
                    trim((string) $value),
                    $request->user()->id,
                    $editedAt,
                );
            }

            if (is_array($pairedFields) && ($existsInPairedFields || $canAddField)) {
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

        if (! $document->update(['ocr_structured_data' => $structuredData])) {
            return back()->withInput()->withErrors([
                'fields' => 'Không thể lưu dữ liệu chuẩn hóa. Vui lòng thử lại.',
            ]);
        }
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
