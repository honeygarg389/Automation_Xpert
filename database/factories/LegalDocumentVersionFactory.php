<?php

namespace Database\Factories;

use App\Modules\Restaurant\Models\LegalDocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalDocumentVersion>
 */
class LegalDocumentVersionFactory extends Factory
{
    protected $model = LegalDocumentVersion::class;

    public function definition(): array
    {
        return [
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'version' => 'v'.fake()->unique()->numberBetween(1, 100000),
            'status' => LegalDocumentVersion::STATUS_DRAFT,
            'content_body' => fake()->paragraphs(5, true),
            'published_slot' => null,
        ];
    }
}
