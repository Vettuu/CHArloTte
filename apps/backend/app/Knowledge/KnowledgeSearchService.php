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
    public function search(string $query, int $limit = 4, ?string $tenantId = null): Collection
    {
        $result = $this->searchWithDiagnostics($query, $limit, $tenantId);

        /** @var array<int, array<string, mixed>> $accepted */
        $accepted = $result['accepted_hits'];

        return collect($accepted);
    }

    /**
     * @return array{
     *   accepted_hits: array<int, array<string, mixed>>,
     *   diagnostic_hits: array<int, array<string, mixed>>,
     *   keyword_candidates: array<int, array<string, mixed>>,
     *   semantic_level: string,
     *   top_score: float|null
     * }
     */
    public function searchWithDiagnostics(string $query, int $limit = 4, ?string $tenantId = null): array
    {
        $tenantId = $tenantId ?? config('knowledge.default_tenant', 'demo');

        if ($structured = $this->repository->structuredLookup($query, $tenantId)) {
            $structuredHit = [[
                'id' => 'structured',
                'title' => 'Dato ufficiale',
                'excerpt' => $structured,
                'confidence_level' => 'high',
            ]];

            return [
                'accepted_hits' => $structuredHit,
                'diagnostic_hits' => $structuredHit,
                'keyword_candidates' => [],
                'semantic_level' => 'high',
                'top_score' => 1.0,
            ];
        }

        $normalizedQuery = TextNormalizer::forEmbedding($query);
        $queryEmbedding = $this->embeddings->embedText($normalizedQuery);
        $queryNorm = $this->vectorNorm($queryEmbedding);

        if ($queryNorm === null) {
            return [
                'accepted_hits' => [],
                'diagnostic_hits' => [],
                'keyword_candidates' => [],
                'semantic_level' => 'low',
                'top_score' => null,
            ];
        }

        $chunkQuery = KnowledgeChunk::query()->where('tenant_id', $tenantId);
        $keywordResults = $this->repository->search($query, $tenantId);
        $keywordCandidates = $keywordResults
            ->take(5)
            ->values()
            ->map(fn (array $document): array => [
                'id' => (string) ($document['id'] ?? ''),
                'title' => (string) ($document['title'] ?? 'Knowledge'),
                'excerpt' => Str::limit(trim((string) ($document['excerpt'] ?? $document['summary'] ?? '')), 220),
                'matched_tokens' => data_get($document, 'keyword_match.matched_tokens'),
                'total_tokens' => data_get($document, 'keyword_match.total_tokens'),
                'match_ratio' => data_get($document, 'keyword_match.match_ratio'),
                'direct_needle_match' => data_get($document, 'keyword_match.direct_needle_match'),
                'strong_term_match' => data_get($document, 'keyword_match.strong_term_match'),
            ])
            ->all();
        $candidateDocuments = $keywordResults
            ->pluck('id')
            ->take(10)
            ->filter();

        if ($candidateDocuments->isNotEmpty()) {
            $chunkQuery->whereIn('document_id', $candidateDocuments->all());
        }

        $chunks = $chunkQuery->get();

        $highThreshold = (float) config('knowledge.score_levels.high', config('knowledge.min_score', 0.70));
        $mediumMinThreshold = (float) config('knowledge.score_levels.medium_min', 0.36);
        $highThreshold = max($highThreshold, $mediumMinThreshold);
        $queryTokens = $this->normalizedQueryTokens($query);

        $scored = $chunks->map(function (KnowledgeChunk $chunk) use ($queryEmbedding, $queryNorm, $queryTokens): ?array {
            $norm = $this->chunkNorm($chunk);
            $score = $this->cosineSimilarity($queryEmbedding, $queryNorm, $chunk->embedding ?? [], $norm);

            if ($score === null) {
                return null;
            }

            $topicBoost = $this->calculateTopicBoost($queryTokens, $chunk);
            $finalScore = min(1.0, $score + $topicBoost);

            return [
                'chunk' => $chunk,
                'score' => $finalScore,
                'base_score' => $score,
                'topic_boost' => $topicBoost,
            ];
        })->filter();

        $results = $scored
            ->filter(fn (?array $candidate): bool => $candidate !== null)
            ->sortByDesc('score');

        $topScore = $results->first()['score'] ?? null;
        $semanticLevel = $this->scoreLevel($topScore, $highThreshold, $mediumMinThreshold);
        $mappedResults = $results
            ->take($limit)
            ->values()
            ->map(function (array $item) use ($semanticLevel): array {
                /** @var KnowledgeChunk $chunk */
                $chunk = $item['chunk'];

                return [
                    'id' => (string) $chunk->id,
                    'title' => $chunk->metadata['title'] ?? 'Knowledge',
                    'excerpt' => Str::limit(trim($chunk->content), 600),
                    'score' => round($item['score'], 3),
                    'base_score' => round((float) ($item['base_score'] ?? 0.0), 3),
                    'topic_boost' => round((float) ($item['topic_boost'] ?? 0.0), 3),
                    'confidence_level' => $semanticLevel,
                ];
            })
            ->all();

        $acceptedHits = $semanticLevel === 'low' ? [] : $mappedResults;
        $diagnosticHits = $mappedResults;

        return [
            'accepted_hits' => $acceptedHits,
            'diagnostic_hits' => $diagnosticHits,
            'keyword_candidates' => $keywordCandidates,
            'semantic_level' => $semanticLevel,
            'top_score' => $topScore !== null ? round((float) $topScore, 3) : null,
        ];
    }

    private function scoreLevel(?float $score, float $highThreshold, float $mediumMinThreshold): string
    {
        if ($score === null) {
            return 'low';
        }

        if ($score >= $highThreshold) {
            return 'high';
        }

        if ($score >= $mediumMinThreshold) {
            return 'medium';
        }

        return 'low';
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

    /**
     * @return array<int, string>
     */
    private function normalizedQueryTokens(string $query): array
    {
        $normalized = Str::of($query)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\\s]/', ' ')
            ->squish()
            ->value();

        if ($normalized === '') {
            return [];
        }

        $tokens = collect(preg_split('/\s+/', $normalized) ?: [])
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->unique()
            ->values();

        $synonyms = collect(config('knowledge.keyword_synonyms', []))
            ->filter(fn ($variants, $canonical) => is_string($canonical) && is_array($variants))
            ->mapWithKeys(function (array $variants, string $canonical): array {
                $canonicalNorm = Str::of($canonical)->lower()->ascii()->value();
                $variantNorm = collect($variants)
                    ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                    ->map(fn (string $value) => Str::of($value)->lower()->ascii()->value())
                    ->push($canonicalNorm)
                    ->unique()
                    ->values()
                    ->all();

                return [$canonicalNorm => $variantNorm];
            })
            ->all();

        return $tokens
            ->map(function (string $token) use ($synonyms): string {
                foreach ($synonyms as $canonical => $variants) {
                    if (in_array($token, $variants, true)) {
                        return $canonical;
                    }
                }

                return $token;
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $queryTokens
     */
    private function calculateTopicBoost(array $queryTokens, KnowledgeChunk $chunk): float
    {
        $topicBoostConfig = (array) config('knowledge.topic_boost', []);
        if (($topicBoostConfig['enabled'] ?? false) !== true || $queryTokens === []) {
            return 0.0;
        }

        $documentId = Str::of((string) $chunk->document_id)->lower()->ascii()->value();
        $metadataTags = collect($chunk->metadata['tags'] ?? [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => Str::of($value)->lower()->ascii()->value())
            ->values();

        $haystack = Str::of(($chunk->metadata['title'] ?? '').' '.($chunk->content ?? ''))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\\s]/', ' ')
            ->squish()
            ->value();

        if ($haystack === '') {
            return 0.0;
        }

        $maxBoost = max(0.0, min(0.25, (float) ($topicBoostConfig['max_boost'] ?? 0.06)));
        $rules = collect($topicBoostConfig['rules'] ?? [])
            ->filter(fn ($rule) => is_array($rule))
            ->values();

        $boost = 0.0;
        foreach ($rules as $rule) {
            $whenAny = collect($rule['when_any'] ?? [])
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->map(fn (string $value) => Str::of($value)->lower()->ascii()->value())
                ->values();
            $targetAny = collect($rule['target_any'] ?? [])
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->map(fn (string $value) => Str::of($value)->lower()->ascii()->value())
                ->values();
            $targetDocumentIds = collect($rule['target_document_ids'] ?? [])
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->map(fn (string $value) => Str::of($value)->lower()->ascii()->value())
                ->values();
            $targetTags = collect($rule['target_tags'] ?? [])
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->map(fn (string $value) => Str::of($value)->lower()->ascii()->value())
                ->values();
            $ruleBoost = (float) ($rule['boost'] ?? 0.0);

            if ($whenAny->isEmpty() || $ruleBoost <= 0.0) {
                continue;
            }

            $queryMatchesRule = collect($queryTokens)
                ->contains(fn (string $token): bool => $whenAny->contains($token));
            if (! $queryMatchesRule) {
                continue;
            }

            $chunkMatchesByText = $targetAny->isNotEmpty()
                && $targetAny->contains(fn (string $term): bool => str_contains($haystack, $term));
            $chunkMatchesByDocumentId = $targetDocumentIds->isNotEmpty()
                && $documentId !== ''
                && $targetDocumentIds->contains($documentId);
            $chunkMatchesByTags = $targetTags->isNotEmpty()
                && $metadataTags->isNotEmpty()
                && $metadataTags->contains(fn (string $tag): bool => $targetTags->contains($tag));
            $chunkMatchesRule = $chunkMatchesByText || $chunkMatchesByDocumentId || $chunkMatchesByTags;
            if (! $chunkMatchesRule) {
                continue;
            }

            $boost += $ruleBoost;
            if ($boost >= $maxBoost) {
                return $maxBoost;
            }
        }

        return min($maxBoost, $boost);
    }
}
