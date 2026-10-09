<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\ImageDocument;
use App\Services\AiImageProcessor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
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
        return view('documents.create', [
            'recentDocuments' => ImageDocument::query()
                ->where('status', ImageDocument::STATUS_COMPLETED)
                ->latest('id')
                ->limit(6)
                ->get(),
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
            'original_path' => $originalPath,
            'status' => ImageDocument::STATUS_PROCESSING,
        ]);

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
        } catch (Throwable $exception) {
            $document->update(['status' => ImageDocument::STATUS_FAILED]);

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
        return $this->imageResponse($document->original_path);
    }

    public function processed(ImageDocument $document): BinaryFileResponse
    {
        if ($document->processed_path === null) {
            abort(404);
        }

        return $this->imageResponse($document->processed_path);
    }

    public function faceCrop(ImageDocument $document): BinaryFileResponse
    {
        $faceCropPath = data_get($document->ocr_structured_data, 'face_crop.stored_path');

        if (! is_string($faceCropPath) || ! str_starts_with($faceCropPath, 'documents/faces/')) {
            abort(404);
        }

        return $this->imageResponse($faceCropPath);
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
}
