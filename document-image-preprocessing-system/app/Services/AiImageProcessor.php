<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiImageProcessor
{
    /**
     * @return array{
     *     processed_image: string,
     *     face_crop_image: ?string,
     *     raw_ocr: array<string, mixed>,
     *     structured_ocr: array<string, mixed>
     * }
     */
    public function process(UploadedFile $image): array
    {
        $stream = fopen($image->getRealPath(), 'r');

        if ($stream === false) {
            throw new RuntimeException('Không thể đọc hình ảnh đã tải lên.');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.ai_engine.url'), '/'))
                ->connectTimeout((int) config('services.ai_engine.connect_timeout'))
                ->timeout((int) config('services.ai_engine.timeout'))
                ->acceptJson()
                ->attach('file', $stream, $image->getClientOriginalName())
                ->post('/process')
                ->throw();
        } finally {
            fclose($stream);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('AI engine trả về JSON không hợp lệ.');
        }

        $encodedImage = $payload['processed_image']['base64'] ?? null;
        $mediaType = $payload['processed_image']['media_type'] ?? null;
        $rawOcr = $payload['ocr']['raw'] ?? null;
        $structuredOcr = $payload['ocr']['structured'] ?? null;
        $encodedFaceCrop = $payload['face_crop']['base64'] ?? null;
        $faceCropMediaType = $payload['face_crop']['media_type'] ?? null;

        if (
            ! is_string($encodedImage)
            || $mediaType !== 'image/jpeg'
            || ! is_array($rawOcr)
            || ! is_array($structuredOcr)
        ) {
            throw new RuntimeException('AI engine trả về kết quả xử lý không đầy đủ.');
        }

        $processedImage = base64_decode($encodedImage, true);

        if ($processedImage === false || $processedImage === '') {
            throw new RuntimeException('Không thể giải mã ảnh kết quả từ AI engine.');
        }

        $faceCropImage = null;

        if ($encodedFaceCrop !== null || $faceCropMediaType !== null) {
            if (! is_string($encodedFaceCrop) || $faceCropMediaType !== 'image/jpeg') {
                throw new RuntimeException('AI engine trả về face crop không hợp lệ.');
            }

            $faceCropImage = base64_decode($encodedFaceCrop, true);

            if ($faceCropImage === false || $faceCropImage === '') {
                throw new RuntimeException('Không thể giải mã face crop từ AI engine.');
            }
        }

        return [
            'processed_image' => $processedImage,
            'face_crop_image' => $faceCropImage,
            'raw_ocr' => $rawOcr,
            'structured_ocr' => $structuredOcr,
        ];
    }
}
