<?php

namespace App\Services;

use App\Models\ImageDocument;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class EncryptedDocumentStorage
{
    public function storeUploadedFile(UploadedFile $file, string $directory): string
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new RuntimeException('Không thể đọc hình ảnh đã tải lên.');
        }

        $extension = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;
        $this->put($path, $contents);

        return $path;
    }

    public function put(string $path, string $contents): void
    {
        if (! Storage::disk('local')->put($path, Crypt::encryptString($contents))) {
            throw new RuntimeException('Không thể lưu dữ liệu hình ảnh đã mã hóa.');
        }
    }

    public function get(ImageDocument $document, string $path): string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            throw new RuntimeException('Không tìm thấy dữ liệu hình ảnh.');
        }

        $contents = $disk->get($path);

        if (! $document->storage_encrypted) {
            return $contents;
        }

        try {
            return Crypt::decryptString($contents);
        } catch (DecryptException $exception) {
            throw new RuntimeException('Không thể giải mã dữ liệu hình ảnh.', previous: $exception);
        }
    }

    public function deleteDocumentFiles(ImageDocument $document): void
    {
        $paths = array_filter([
            $document->original_path,
            $document->processed_path,
            data_get($document->ocr_structured_data, 'face_crop.stored_path'),
        ]);

        Storage::disk('local')->delete($paths);
    }

    public function encryptLegacyDocument(ImageDocument $document): void
    {
        if ($document->storage_encrypted) {
            return;
        }

        $disk = Storage::disk('local');
        $paths = array_filter([
            $document->original_path,
            $document->processed_path,
            data_get($document->ocr_structured_data, 'face_crop.stored_path'),
        ]);
        $originalContents = [];

        foreach ($paths as $path) {
            if ($disk->exists($path)) {
                $originalContents[$path] = $disk->get($path);
            }
        }

        try {
            foreach ($originalContents as $path => $contents) {
                $this->put($path, $contents);
            }

            $document->update(['storage_encrypted' => true]);
        } catch (\Throwable $exception) {
            foreach ($originalContents as $path => $contents) {
                $disk->put($path, $contents);
            }

            throw $exception;
        }
    }
}
