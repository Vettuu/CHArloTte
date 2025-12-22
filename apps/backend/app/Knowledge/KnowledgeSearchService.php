<?php

namespace App\Knowledge;

use App\Models\KnowledgeChunk;
use App\Services\OpenAIEmbeddingService;
use App\Support\TextNormalizer;
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

        $normalizedQuery = TextNormalizer::forEmbedding($query);
        $queryEmbedding = $this->embeddings->embedText($normalizedQuery);
        $queryNorm = $this->vectorNorm($queryEmbedding);

        if ($queryNorm === null) {
            return collect();
        }

        $chunkQuery = KnowledgeChunk::query();
        $candidateDocuments = $this->repository->search($query)
            ->pluck('id')
            ->take(5)
            ->filter();

        if ($candidateDocuments->isNotEmpty()) {
            $chunkQuery->whereIn('document_id', $candidateDocuments->all());
        }

        $chunks = $chunkQuery->get();

        $minScore = (float) config('knowledge.min_score', 0.79);

        $scored = $chunks->map(function (KnowledgeChunk $chunk) use ($queryEmbedding, $queryNorm): ?array {
            $norm = $this->chunkNorm($chunk);
            $score = $this->cosineSimilarity($queryEmbedding, $queryNorm, $chunk->embedding ?? [], $norm);

            if ($score === null) {
                return null;
            }

            return [
                'chunk' => $chunk,
                'score' => $score,
            ];
        })->filter();

        $results = $scored
            ->filter(fn (?array $candidate): bool => $candidate !== null)
            ->sortByDesc('score');

        $topScore = $results->first()['score'] ?? null;

        if ($topScore === null || $topScore < $minScore) {
            return collect();
        }

        return $results
            ->take($limit)
            ->values()
            ->map(function (array $item): array {
                /** @var KnowledgeChunk $chunk */
                $chunk = $item['chunk'];

                return [
                    'id' => (string) $chunk->id,
                    'title' => $chunk->metadata['title'] ?? 'Knowledge',
                    'excerpt' => Str::limit(trim($chunk->content), 600),
                    'score' => round($item['score'], 3),
                ];
            });
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function vectorNorm(array $vector): ?float
    {
        if ($vector === []) {
            return null;
        }

        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        return sqrt($sum);
    }

    private function chunkNorm(KnowledgeChunk $chunk): ?float
    {
        if ($chunk->embedding_norm !== null) {
            return $chunk->embedding_norm;
        }

        $norm = $this->vectorNorm($chunk->embedding ?? []);

        if ($norm !== null) {
            $chunk->embedding_norm = $norm;
            $chunk->save();
        }

        return $norm;
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosineSimilarity(array $a, float $normA, array $b, ?float $normB): ?float
    {
        if (empty($a) || empty($b) || count($a) !== count($b) || $normA === 0.0 || $normB === null || $normB === 0.0) {
            return null;
        }

        $dot = 0.0;

        foreach ($a as $index => $valueA) {
            $valueB = $b[$index] ?? null;

            if ($valueB === null) {
                return null;
            }

            $dot += $valueA * $valueB;
        }

        return $dot / ($normA * $normB);
    }
}
