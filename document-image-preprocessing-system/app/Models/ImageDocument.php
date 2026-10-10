<?php

namespace App\Models;

use Database\Factories\ImageDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'processed_path',
        'ocr_raw_data',
        'ocr_structured_data',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocr_raw_data' => 'array',
            'ocr_structured_data' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
