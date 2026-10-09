<?php

namespace App\Models;

use Database\Factories\ImageDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImageDocument extends Model
{
    /** @use HasFactory<ImageDocumentFactory> */
    use HasFactory;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'original_path',
        'processed_path',
        'ocr_raw_data',
        'ocr_structured_data',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocr_raw_data' => 'array',
            'ocr_structured_data' => 'array',
        ];
    }
}
