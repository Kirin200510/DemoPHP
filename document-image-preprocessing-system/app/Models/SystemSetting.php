<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class SystemSetting extends Model
{
    public const OCR_PROVIDER_KEY = 'ocr_provider';

    /**
     * @var list<string>
     */
    private const OCR_PROVIDERS = [
        'local_hybrid',
        'fpt_ai_reader',
        'vnpt_ekyc',
        'external_ekyc',
    ];

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * @return list<string>
     */
    public static function ocrProviders(): array
    {
        return self::OCR_PROVIDERS;
    }

    public static function syncOcrProviderState(string $provider): void
    {
        $path = (string) config('services.ai_engine.ocr_provider_state_path');
        File::ensureDirectoryExists(dirname($path));
        File::replace($path, json_encode([
            'provider' => $provider,
        ], JSON_THROW_ON_ERROR));
    }

    public static function ocrProvider(): string
    {
        if (! Schema::hasTable('system_settings')) {
            return (string) config('services.ai_engine.default_ocr_provider');
        }

        $configuredProvider = self::query()
            ->where('key', self::OCR_PROVIDER_KEY)
            ->value('value');

        if (is_string($configuredProvider) && in_array($configuredProvider, self::OCR_PROVIDERS, true)) {
            return $configuredProvider;
        }

        return (string) config('services.ai_engine.default_ocr_provider');
    }
}
