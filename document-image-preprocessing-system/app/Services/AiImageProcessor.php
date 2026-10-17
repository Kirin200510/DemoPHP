<?php

namespace App\Services;

use App\Exceptions\AiEngineResponseException;
use App\Exceptions\AiEngineUnavailableException;
use App\Exceptions\InvalidDocumentImageException;
use App\Models\SystemSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
        $response = $this->requestProcessing($image);

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new AiEngineResponseException('AI engine trả về JSON không hợp lệ.');
        }

        return $this->decodeProcessingPayload($payload);
    }

    /**
     * @param  list<UploadedFile>  $images
     * @return array<int, array<string, mixed>|Throwable>
     */
    public function processBatch(array $images): array
    {
        $pending = $images;
        $outcomes = [];
        $provider = SystemSetting::ocrProvider();
        $attempts = max(1, (int) config('services.ai_engine.retry_attempts'));

        for ($attempt = 1; $attempt <= $attempts && $pending !== []; $attempt++) {
            $retryImages = [];

            try {
                $response = $this->requestProcessing(array_values($pending), $provider);
                $documents = $response->json('documents');

                if (! is_array($documents) || ! array_is_list($documents) || count($documents) !== count($pending)) {
                    throw new AiEngineResponseException('AI engine trả về danh sách kết quả không đầy đủ.');
                }

                foreach (array_keys($pending) as $position => $index) {
                    $item = $documents[$position];

                    try {
                        if (! is_array($item)) {
                            throw new AiEngineResponseException('AI engine trả về kết quả ảnh không hợp lệ.');
                        }

                        if (($item['status'] ?? null) === 'completed') {
                            $outcomes[$index] = $this->decodeProcessingPayload($item);

                            continue;
                        }

                        if (($item['http_status'] ?? null) === 422) {
                            throw $this->invalidDocumentImageException($item['detail'] ?? null);
                        }

                        if (is_int($item['http_status'] ?? null) && $item['http_status'] >= 500) {
                            throw new AiEngineUnavailableException('AI engine không thể xử lý ảnh sau khi đã thử lại.');
                        }

                        throw new AiEngineResponseException('AI engine từ chối yêu cầu xử lý ảnh.');
                    } catch (Throwable $exception) {
                        $outcomes[$index] = $exception;

                        if ($exception instanceof AiEngineUnavailableException) {
                            $retryImages[$index] = $pending[$index];
                        }
                    }
                }
            } catch (Throwable $exception) {
                foreach ($pending as $index => $image) {
                    $outcomes[$index] = $exception;

                    if ($exception instanceof AiEngineUnavailableException) {
                        $retryImages[$index] = $image;
                    }
                }
            }

            $pending = $retryImages;

            if ($pending !== [] && $attempt < $attempts) {
                $this->waitBeforeRetry(max(0, (int) config('services.ai_engine.retry_delay_milliseconds')), $attempt);
            }
        }

        ksort($outcomes);

        return $outcomes;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{processed_image: string, face_crop_image: ?string, raw_ocr: array<string, mixed>, structured_ocr: array<string, mixed>}
     */
    private function decodeProcessingPayload(array $payload): array
    {
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
            throw new AiEngineResponseException('AI engine trả về kết quả xử lý không đầy đủ.');
        }

        $processedImage = base64_decode($encodedImage, true);

        if ($processedImage === false || $processedImage === '') {
            throw new AiEngineResponseException('Không thể giải mã ảnh kết quả từ AI engine.');
        }

        $faceCropImage = null;

        if ($encodedFaceCrop !== null || $faceCropMediaType !== null) {
            if (! is_string($encodedFaceCrop) || $faceCropMediaType !== 'image/jpeg') {
                throw new AiEngineResponseException('AI engine trả về face crop không hợp lệ.');
            }

            $faceCropImage = base64_decode($encodedFaceCrop, true);

            if ($faceCropImage === false || $faceCropImage === '') {
                throw new AiEngineResponseException('Không thể giải mã face crop từ AI engine.');
            }
        }

        return [
            'processed_image' => $processedImage,
            'face_crop_image' => $faceCropImage,
            'raw_ocr' => $rawOcr,
            'structured_ocr' => $structuredOcr,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $structuredResults
     * @return array<string, mixed>
     */
    public function matchDocumentSides(array $structuredResults): array
    {
        $response = Http::baseUrl(rtrim((string) config('services.ai_engine.url'), '/'))
            ->connectTimeout((int) config('services.ai_engine.connect_timeout'))
            ->timeout((int) config('services.ai_engine.timeout'))
            ->acceptJson()
            ->withHeaders([
                'X-OCR-Provider' => SystemSetting::ocrProvider(),
            ])
            ->post('/document-sides/match', $structuredResults);

        if ($response->serverError()) {
            throw new AiEngineUnavailableException(
                'AI engine không thể ghép dữ liệu hai mặt giấy tờ.',
            );
        }

        $payload = $response->json();
        $pairErrors = [
            'ERR_PAIR_MISMATCH' => [400, 'not_matched', 'Mặt trước và mặt sau không thuộc cùng một giấy tờ hoặc có thông tin định danh mâu thuẫn.'],
            'ERR_PAIR_SIDE_INVALID' => [422, 'needs_review', 'Cần một ảnh mặt trước và một ảnh mặt sau của cùng giấy tờ.'],
            'ERR_PAIR_INSUFFICIENT_EVIDENCE' => [422, 'needs_review', 'Chưa đủ thông tin để đối chiếu hai mặt. Hãy chọn ảnh rõ hơn hoặc yêu cầu nhân viên kiểm tra.'],
            'ERR_PAIR_CONFLICTING_EVIDENCE' => [422, 'needs_review', 'Các nguồn dữ liệu định danh mâu thuẫn. Cần kiểm tra trước khi gộp hồ sơ.'],
        ];
        $errorCode = data_get($payload, 'error.code');
        $pairError = is_string($errorCode) ? ($pairErrors[$errorCode] ?? null) : null;

        if ($pairError !== null && $response->status() === $pairError[0]
            && data_get($payload, 'status') === $pairError[1]) {
            unset($payload['paired_normalized_ocr']);
            $payload['error']['message'] = $pairError[2];
            $payload['http_status'] = $response->status();

            return $payload;
        }

        if ($response->failed()) {
            throw new AiEngineResponseException(
                'AI engine từ chối yêu cầu ghép dữ liệu hai mặt giấy tờ.',
            );
        }

        if (! is_array($payload) || ! in_array($payload['status'] ?? null, ['matched', 'not_matched', 'needs_review'], true)) {
            throw new AiEngineResponseException(
                'AI engine trả về kết quả ghép hai mặt không hợp lệ.',
            );
        }

        if ($payload['status'] === 'matched') {
            if (! in_array($payload['front_input_index'] ?? null, [0, 1], true)
                || ! in_array($payload['back_input_index'] ?? null, [0, 1], true)
                || $payload['front_input_index'] === $payload['back_input_index']
                || ! is_array($payload['paired_normalized_ocr'] ?? null)
                || data_get($payload, 'paired_normalized_ocr.document.document_type') !== 'citizen_identity_card'
                || ! is_array(data_get($payload, 'paired_normalized_ocr.fields'))
                || data_get($payload, 'paired_normalized_ocr.fields') === []) {
                throw new AiEngineResponseException('AI engine trả về hồ sơ ghép hai mặt không đầy đủ.');
            }
        } else {
            unset($payload['paired_normalized_ocr']);
        }

        return $payload;
    }

    /**
     * @param  UploadedFile|list<UploadedFile>  $image
     */
    private function requestProcessing(UploadedFile|array $image, ?string $provider = null): Response
    {
        $isBatch = is_array($image);
        $images = $isBatch ? $image : [$image];
        $provider ??= SystemSetting::ocrProvider();
        $attempts = $isBatch ? 1 : max(1, (int) config('services.ai_engine.retry_attempts'));
        $retryDelayMilliseconds = max(0, (int) config('services.ai_engine.retry_delay_milliseconds'));
        $lastConnectionException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $streams = [];
            try {
                $request = Http::baseUrl(rtrim((string) config('services.ai_engine.url'), '/'))
                    ->connectTimeout((int) config('services.ai_engine.connect_timeout'))
                    ->timeout($isBatch
                        ? max((int) config('services.ai_engine.timeout'), (int) config('services.ai_engine.batch_timeout'))
                        : (int) config('services.ai_engine.timeout'))
                    ->acceptJson()
                    ->withHeaders([
                        'X-OCR-Provider' => $provider,
                    ]);

                foreach ($images as $uploadedImage) {
                    $stream = fopen($uploadedImage->getRealPath(), 'r');

                    if ($stream === false) {
                        throw new RuntimeException('Không thể đọc hình ảnh đã tải lên.');
                    }

                    $streams[] = $stream;
                    $request->attach($isBatch ? 'files' : 'file', $stream, $uploadedImage->getClientOriginalName());
                }

                $response = $request->post($isBatch ? '/process-batch' : '/process');
            } catch (ConnectionException $exception) {
                $lastConnectionException = $exception;

                if ($attempt < $attempts) {
                    $this->waitBeforeRetry($retryDelayMilliseconds, $attempt);

                    continue;
                }

                throw new AiEngineUnavailableException(
                    'AI engine không thể kết nối sau khi đã thử lại.',
                    previous: $exception,
                );
            } finally {
                foreach ($streams as $stream) {
                    fclose($stream);
                }
            }

            if ($response->status() === 422) {
                throw $this->invalidDocumentImageException($response->json('detail'));
            }

            if ($response->serverError()) {
                if ($attempt < $attempts) {
                    $this->waitBeforeRetry($retryDelayMilliseconds, $attempt);

                    continue;
                }

                throw new AiEngineUnavailableException(
                    'AI engine không thể xử lý yêu cầu sau khi đã thử lại.',
                );
            }

            if ($response->failed()) {
                throw new AiEngineResponseException(
                    'AI engine từ chối yêu cầu xử lý hình ảnh.',
                );
            }

            return $response;
        }

        throw new AiEngineUnavailableException(
            'AI engine không thể kết nối sau khi đã thử lại.',
            previous: $lastConnectionException,
        );
    }

    private function invalidDocumentImageException(mixed $detail): InvalidDocumentImageException
    {
        $reason = is_array($detail) && is_string($detail['code'] ?? null)
            ? $detail['code']
            : 'invalid_document_image';

        $message = match ($reason) {
            'image_too_blurry_or_low_contrast' => 'Ảnh quá mờ hoặc thiếu tương phản. Vui lòng chụp lại rõ nét hơn.',
            'image_severely_overexposed' => 'Ảnh bị cháy sáng nghiêm trọng. Vui lòng chụp lại, tránh ánh sáng phản chiếu trực tiếp.',
            'document_frame_not_detected' => 'Không nhận diện được khung giấy tờ. Vui lòng chụp đầy đủ bốn cạnh giấy tờ trên nền tương phản và thử lại.',
            default => 'Hình ảnh không đạt điều kiện để xử lý. Vui lòng chọn ảnh giấy tờ hợp lệ và thử lại.',
        };

        return new InvalidDocumentImageException($reason, $message);
    }

    private function waitBeforeRetry(int $retryDelayMilliseconds, int $attempt): void
    {
        if ($retryDelayMilliseconds > 0) {
            usleep($retryDelayMilliseconds * $attempt * 1000);
        }
    }
}
