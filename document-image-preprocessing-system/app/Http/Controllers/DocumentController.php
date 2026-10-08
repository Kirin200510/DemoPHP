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
            $processedImage = $imageProcessor->process($image);
            $processedPath = 'documents/processed/'.Str::uuid().'.jpg';

            if (! Storage::disk('local')->put($processedPath, $processedImage)) {
                throw new RuntimeException('Không thể lưu hình ảnh sau khi xử lý.');
            }

            $document->update([
                'processed_path' => $processedPath,
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
            ->with('success', 'Hình ảnh đã được xử lý và lưu thành công.');
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
