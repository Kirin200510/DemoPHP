<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\AuditLog;
use App\Models\ImageDocument;
use App\Services\AiImageProcessor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DocumentController extends Controller
{
    public function create(): View
    {
        $user = request()->user();
        $recentDocumentsQuery = ImageDocument::query()
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
    ): RedirectResponse {
        $image = $request->file('image');
        $originalPath = $image->store('documents/originals', 'local');

        if ($originalPath === false) {
            return back()->withErrors([
                'image' => 'Không thể lưu hình ảnh đã tải lên. Vui lòng thử lại.',
            ]);
        }

        $document = ImageDocument::query()->create([
            'user_id' => $request->user()->id,
            'original_path' => $originalPath,
            'status' => ImageDocument::STATUS_PROCESSING,
        ]);
        $this->recordAudit('document.uploaded', $document);

        try {
            $processingResult = $imageProcessor->process($image);
            $processedPath = 'documents/processed/'.Str::uuid().'.jpg';
            $faceCropPath = null;

            if (! Storage::disk('local')->put($processedPath, $processingResult['processed_image'])) {
                throw new RuntimeException('Không thể lưu hình ảnh sau khi xử lý.');
            }

            if ($processingResult['face_crop_image'] !== null) {
                $faceCropPath = 'documents/faces/'.Str::uuid().'.jpg';

                if (! Storage::disk('local')->put($faceCropPath, $processingResult['face_crop_image'])) {
                    throw new RuntimeException('Không thể lưu ảnh khuôn mặt đã trích xuất.');
                }
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
        } catch (Throwable $exception) {
            $document->update(['status' => ImageDocument::STATUS_FAILED]);
            $this->recordAudit('document.processing_failed', $document, [
                'exception' => $exception::class,
            ]);

            Log::error('Document image preprocessing failed.', [
                'document_id' => $document->id,
                'exception' => $exception,
            ]);

            return redirect()
                ->route('documents.show', $document)
                ->withErrors([
                    'processing' => 'AI engine không thể xử lý hình ảnh. Hãy kiểm tra server AI rồi thử lại.',
                ]);
        }

        return redirect()
            ->route('documents.show', $document)
            ->with('success', 'Hình ảnh và dữ liệu OCR đã được xử lý, chuẩn hóa và lưu thành công.');
    }

    public function show(ImageDocument $document): View
    {
        return view('documents.show', ['document' => $document]);
    }

    public function original(ImageDocument $document): BinaryFileResponse
    {
        $this->recordAudit('document.original_viewed', $document);

        return $this->imageResponse($document->original_path);
    }

    public function processed(ImageDocument $document): BinaryFileResponse
    {
        if ($document->processed_path === null) {
            abort(404);
        }

        $this->recordAudit('document.processed_viewed', $document);

        return $this->imageResponse($document->processed_path);
    }

    public function faceCrop(ImageDocument $document): BinaryFileResponse
    {
        $faceCropPath = data_get($document->ocr_structured_data, 'face_crop.stored_path');

        if (! is_string($faceCropPath) || ! str_starts_with($faceCropPath, 'documents/faces/')) {
            abort(404);
        }

        $this->recordAudit('document.face_crop_viewed', $document);

        return $this->imageResponse($faceCropPath);
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

        foreach ($validated['fields'] as $fieldName => $value) {
            if (array_key_exists($fieldName, $fields) && is_array($fields[$fieldName])) {
                $fields[$fieldName]['normalized_value'] = trim((string) $value);
                $fields[$fieldName]['edited_by'] = $request->user()->id;
                $fields[$fieldName]['edited_at'] = now()->toIso8601String();
            }
        }

        $structuredData['fields'] = $fields;
        $document->update(['ocr_structured_data' => $structuredData]);
        $this->recordAudit('document.structured_ocr_updated', $document, [
            'fields' => array_keys($validated['fields']),
        ]);

        return back()->with('success', 'Dữ liệu OCR chuẩn hóa đã được cập nhật.');
    }

    public function submitForReview(ImageDocument $document): RedirectResponse
    {
        Gate::authorize('submitForReview', $document);
        $document->update([
            'status' => ImageDocument::STATUS_PENDING_REVIEW,
            'review_notes' => null,
        ]);
        $this->recordAudit('document.submitted_for_review', $document);

        return back()->with('success', 'Đã gửi yêu cầu xác thực hồ sơ cho nhân viên xử lý.');
    }

    public function verify(Request $request, ImageDocument $document): RedirectResponse
    {
        Gate::authorize('review', $document);
        $document->update([
            'status' => ImageDocument::STATUS_VERIFIED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => null,
        ]);
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
        $document->update([
            'status' => ImageDocument::STATUS_RESUBMISSION_REQUIRED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $validated['reason'],
        ]);
        $this->recordAudit('document.resubmission_requested', $document);

        return redirect()
            ->route('documents.create')
            ->with('success', 'Đã yêu cầu khách hàng gửi lại thông tin và chuyển hồ sơ khỏi hàng đợi.');
    }

    private function imageResponse(string $path): BinaryFileResponse
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            abort(404);
        }

        return response()->file($disk->path($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
}
