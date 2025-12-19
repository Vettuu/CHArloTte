<?php

namespace App\Knowledge;

use App\Models\KnowledgeChunk;
use App\Services\OpenAIEmbeddingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class KnowledgeSearchService
{
    public function __construct(
        private readonly KnowledgeRepository $repository,
        private readonly OpenAIEmbeddingService $embeddings,
    ) {
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 3): Collection
    {
        if ($structured = $this->repository->structuredLookup($query)) {
            return collect([[
                'id' => 'structured',
                'title' => 'Dato ufficiale',
                'excerpt' => $structured,
            ]]);
        }

        $queryEmbedding = $this->embeddings->embedText($query);
        $chunks = KnowledgeChunk::query()->get();

        $scored = $chunks->map(function (KnowledgeChunk $chunk) use ($queryEmbedding): ?array {
            $score = $this->cosineSimilarity($queryEmbedding, $chunk->embedding ?? []);

            if ($score === null) {
                return null;
            }

            return [
                'chunk' => $chunk,
                'score' => $score,
            ];
        })->filter();

        return $scored
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->map(function (array $item): array {
                /** @var KnowledgeChunk $chunk */
                $chunk = $item['chunk'];

                return [
                    'id' => (string) $chunk->id,
                    'title' => $chunk->metadata['title'] ?? 'Knowledge',
                    'excerpt' => Str::limit(trim($chunk->content), 600),
                ];
            });
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosineSimilarity(array $a, array $b): ?float
    {
        if (empty($a) || empty($b) || count($a) !== count($b)) {
            return null;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $valueA) {
            $valueB = $b[$index] ?? null;

            if ($valueB === null) {
                return null;
            }

            $dot += $valueA * $valueB;
            $normA += $valueA * $valueA;
            $normB += $valueB * $valueB;
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return null;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
