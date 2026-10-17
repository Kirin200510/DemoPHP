<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ImageDocument;

class DocumentDataRetentionService
{
    public function __construct(private readonly EncryptedDocumentStorage $documentStorage) {}

    public function purgeExpiredDocuments(): int
    {
        $purged = 0;

        ImageDocument::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNull('purged_at')
            ->eachById(function (ImageDocument $document) use (&$purged): void {
                $this->documentStorage->deleteDocumentFiles($document);
                $document->update([
                    'processed_path' => null,
                    'ocr_raw_data' => null,
                    'ocr_structured_data' => null,
                    'purged_at' => now(),
                ]);
                AuditLog::query()->create([
                    'action' => 'document.data_purged',
                    'auditable_type' => $document->getMorphClass(),
                    'auditable_id' => $document->getKey(),
                    'metadata' => ['expires_at' => $document->expires_at?->toIso8601String()],
                ]);
                $purged++;
            });

        return $purged;
    }
}
