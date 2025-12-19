<?php

namespace Database\Factories;

use App\Models\KnowledgeChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeChunkFactory extends Factory
{
    protected $model = KnowledgeChunk::class;

    public function definition(): array
    {
        return [
            'document_id' => 'doc_'.$this->faker->uuid(),
            'content' => $this->faker->paragraph(),
            'metadata' => [
                'title' => $this->faker->sentence(),
            ],
            'embedding' => [0.1, 0.1, 0.1],
        ];
    }
}
