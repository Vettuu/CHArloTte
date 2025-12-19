<?php

namespace Tests\Feature;

use App\Models\KnowledgeChunk;
use App\Services\OpenAIEmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_structured_snippet(): void
    {
        $response = $this->postJson('/api/knowledge/search', [
            'query' => 'nome responsabile',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.title', 'Dato ufficiale');
    }

    public function test_it_returns_semantic_matches(): void
    {
        KnowledgeChunk::query()->delete();

        KnowledgeChunk::create([
            'document_id' => 'doc-1',
            'content' => 'Programma sala A con workshop avanzato',
            'metadata' => ['title' => 'Doc1'],
            'embedding' => [1, 0, 0],
        ]);

        KnowledgeChunk::create([
            'document_id' => 'doc-2',
            'content' => 'Programma sala B',
            'metadata' => ['title' => 'Doc2'],
            'embedding' => [0, 1, 0],
        ]);

        $this->mock(OpenAIEmbeddingService::class, function ($mock): void {
            $mock->shouldReceive('embedText')
                ->andReturn([1, 0, 0]);
        });

        $response = $this->postJson('/api/knowledge/search', [
            'query' => 'programma workshop sala A',
            'limit' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.title', 'Doc1')
            ->assertJsonPath('data.0.excerpt', 'Programma sala A con workshop avanzato');
    }

    public function test_it_validates_query(): void
    {
        $response = $this->postJson('/api/knowledge/search', [
            'query' => '',
        ]);

        $response->assertStatus(422);
    }
}
