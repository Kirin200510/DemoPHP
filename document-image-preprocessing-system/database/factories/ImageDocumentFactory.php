<?php

namespace Database\Factories;

use App\Models\ImageDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageDocument>
 */
class ImageDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'original_path' => 'documents/originals/'.$this->faker->uuid().'.jpg',
            'processed_path' => 'documents/processed/'.$this->faker->uuid().'.jpg',
            'status' => ImageDocument::STATUS_COMPLETED,
        ];
    }
}
