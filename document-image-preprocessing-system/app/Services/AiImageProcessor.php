<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiImageProcessor
{
    public function process(UploadedFile $image): string
    {
        $stream = fopen($image->getRealPath(), 'r');

        if ($stream === false) {
            throw new RuntimeException('Không thể đọc hình ảnh đã tải lên.');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.ai_engine.url'), '/'))
                ->connectTimeout((int) config('services.ai_engine.connect_timeout'))
                ->timeout((int) config('services.ai_engine.timeout'))
                ->accept('image/jpeg')
                ->attach('file', $stream, $image->getClientOriginalName())
                ->post('/preprocess')
                ->throw();
        } finally {
            fclose($stream);
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        $processedImage = $response->body();

        if (! str_starts_with($contentType, 'image/jpeg') || $processedImage === '') {
            throw new RuntimeException('AI engine trả về dữ liệu hình ảnh không hợp lệ.');
        }

        return $processedImage;
    }
}
