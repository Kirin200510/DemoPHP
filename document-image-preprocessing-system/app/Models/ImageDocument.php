<?php

namespace App\Models;

use Database\Factories\ImageDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImageDocument extends Model
{
    /** @use HasFactory<ImageDocumentFactory> */
    use HasFactory;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_RESUBMISSION_REQUIRED = 'resubmission_required';

    protected $fillable = [
        'original_path',
        'user_id',
        'merged_into_document_id',
        'processed_path',
        'storage_encrypted',
        'ocr_raw_data',
        'ocr_structured_data',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'expires_at',
        'purged_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Return the canonical dossier that owns this side of a matched document.
     *
     * @return BelongsTo<ImageDocument, $this>
     */
    public function mergedIntoDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_document_id');
    }

    /**
     * Return document sides retained under this canonical dossier.
     *
     * @return HasMany<ImageDocument, $this>
     */
    public function mergedDocumentSides(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_document_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocr_raw_data' => 'array',
            'ocr_structured_data' => 'array',
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
            'purged_at' => 'datetime',
            'storage_encrypted' => 'boolean',
        ];
    }
}
