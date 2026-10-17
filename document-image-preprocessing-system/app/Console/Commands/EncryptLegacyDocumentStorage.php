<?php

namespace App\Console\Commands;

use App\Models\ImageDocument;
use App\Services\EncryptedDocumentStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('documents:encrypt-legacy-storage {--dry-run : Show the number of records without modifying files}')]
#[Description('Encrypt legacy document files stored before encryption at rest was enabled.')]
class EncryptLegacyDocumentStorage extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(EncryptedDocumentStorage $documentStorage): int
    {
        $documents = ImageDocument::query()
            ->where('storage_encrypted', false)
            ->whereNull('purged_at');

        if ($this->option('dry-run')) {
            $this->info("{$documents->count()} legacy document record(s) would be encrypted.");

            return self::SUCCESS;
        }

        $encrypted = 0;

        $documents->eachById(function (ImageDocument $document) use ($documentStorage, &$encrypted): void {
            $documentStorage->encryptLegacyDocument($document);
            $encrypted++;
        });

        $this->info("Encrypted {$encrypted} legacy document record(s).");

        return self::SUCCESS;
    }
}
