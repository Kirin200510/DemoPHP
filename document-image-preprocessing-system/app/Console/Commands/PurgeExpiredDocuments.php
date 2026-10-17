<?php

namespace App\Console\Commands;

use App\Services\DocumentDataRetentionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('documents:purge-expired')]
#[Description('Purge document data that has passed its retention period.')]
class PurgeExpiredDocuments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DocumentDataRetentionService $retentionService): int
    {
        $purged = $retentionService->purgeExpiredDocuments();

        $this->info("Purged {$purged} expired document record(s).");

        return self::SUCCESS;
    }
}
