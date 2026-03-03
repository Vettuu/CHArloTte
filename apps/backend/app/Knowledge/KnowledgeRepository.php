<?php

namespace App\Knowledge;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class KnowledgeRepository
{
    private array $structuredData = [];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function all(?string $tenantId = null): Collection
    {
        $this->structuredData = [];
        $tenantId = $tenantId ?? config('knowledge.default_tenant', 'demo');

        $metadata = collect(json_decode(
            File::get(resource_path('knowledge/'.$tenantId.'/metadata.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        ));

        return $metadata->map(function (array $entry) use ($tenantId): array {
            $files = $entry['file'];
            $files = is_array($files) ? $files : [$files];

            $content = collect($files)
                ->map(fn ($file) => $this->loadContent($file, $tenantId))
                ->filter()
                ->implode("\n\n");

            return [
                'id' => $entry['id'],
                'title' => $entry['title'],
                'tags' => $entry['tags'],
                'summary' => $entry['summary'],
                'content' => $content,
            ];
        });
    }

    public function find(string $id, ?string $tenantId = null): ?array
    {
        return $this->all($tenantId)->firstWhere('id', $id);
    }

    /**
     * Simple keyword search across all knowledge documents.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function search(string $query, ?string $tenantId = null): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }
        $tenantId = $tenantId ?? config('knowledge.default_tenant', 'demo');

        $normalizedQuery = $this->normalize($query);

        if ($normalizedQuery === '') {
            $normalizedQuery = mb_strtolower($query);
        }
        $tokens = $this->expandTokens($this->tokenize($normalizedQuery));
        $documents = $this->all($tenantId);
        $summaryBonus = max(0.0, (float) config('knowledge.keyword_ranking.summary_bonus', 0.05));
        $maxKeywordScore = max(0.1, (float) config('knowledge.keyword_ranking.max_score', 1.0));

        return $documents
            ->map(function (array $document) use ($tokens, $normalizedQuery, $summaryBonus, $maxKeywordScore): ?array {
                $summary = trim((string) ($document['summary'] ?? ''));
                $summaryAnalysis = $this->matchAnalysis(
                    $this->normalize($summary),
                    $tokens,
                    $normalizedQuery
                );
                $summaryMatched = (bool) ($summaryAnalysis['matched'] ?? false);
                $summaryRankScore = $summaryMatched
                    ? min($maxKeywordScore, (float) ($summaryAnalysis['keyword_score'] ?? 0.0) + $summaryBonus)
                    : -1.0;

                $content = (string) ($document['content'] ?? '');
                $contentMatched = false;
                $contentAnalysis = null;
                $contentExcerpt = '';
                $contentRankScore = -1.0;

                if ($content !== '') {
                    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
                    foreach ($lines as $line) {
                        $line = trim((string) $line);
                        if ($line === '') {
                            continue;
                        }

                        $analysis = $this->matchAnalysis($this->normalize($line), $tokens, $normalizedQuery);
                        if (! ($analysis['matched'] ?? false)) {
                            continue;
                        }

                        $candidateScore = (float) ($analysis['keyword_score'] ?? 0.0);
                        if (! $contentMatched || $candidateScore > $contentRankScore) {
                            $contentMatched = true;
                            $contentAnalysis = $analysis;
                            $contentExcerpt = $line;
                            $contentRankScore = $candidateScore;
                        }
                    }

                    if (! $contentMatched) {
                        $normalizedContent = $this->normalize($content);
                        $analysis = $this->matchAnalysis($normalizedContent, $tokens, $normalizedQuery);
                        if ($analysis['matched'] ?? false) {
                            $contentMatched = true;
                            $contentAnalysis = $analysis;
                            $contentRankScore = (float) ($analysis['keyword_score'] ?? 0.0);
                            $position = mb_strpos($normalizedContent, $normalizedQuery);
                            if ($position !== false) {
                                $contentExcerpt = trim(mb_substr($content, max($position - 80, 0), 200));
                            }
                        }
                    }
                }

                if (! $summaryMatched && ! $contentMatched) {
                    return null;
                }

                $pickSummary = $summaryMatched && $summaryRankScore >= $contentRankScore;
                if ($pickSummary) {
                    $summaryAnalysis['source'] = 'summary';
                    $summaryAnalysis['keyword_score'] = round($summaryRankScore, 3);
                    $document['excerpt'] = $summary;
                    $document['keyword_match'] = $summaryAnalysis;
                } else {
                    /** @var array<string, mixed> $contentAnalysis */
                    $contentAnalysis = is_array($contentAnalysis) ? $contentAnalysis : [];
                    $contentAnalysis['source'] = 'content';
                    $contentAnalysis['keyword_score'] = round(max(0.0, $contentRankScore), 3);
                    $document['excerpt'] = $contentExcerpt;
                    $document['keyword_match'] = $contentAnalysis;
                }

                return $document;
            })
            ->filter()
            ->sortByDesc(fn (array $document): float => (float) data_get($document, 'keyword_match.keyword_score', 0.0))
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function tokenize(string $value): Collection
    {
        $minTokenLength = max(1, (int) config('knowledge.keyword_min_token_length', 3));
        $stopwords = collect(config('knowledge.keyword_stopwords', []))
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => Str::of($item)->lower()->ascii()->value())
            ->unique()
            ->values();
        $shortTokenAllowlist = collect(config('knowledge.keyword_short_token_allowlist', []))
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => Str::of($item)->lower()->ascii()->value())
            ->unique()
            ->values();

        return collect(preg_split('/\s+/', $value) ?: [])
            ->filter(
                fn (string $token): bool => mb_strlen($token) >= $minTokenLength
                    || $shortTokenAllowlist->contains($token)
            )
            ->reject(fn (string $token): bool => $stopwords->contains($token))
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, string>  $tokens
     * @return Collection<int, string>
     */
    private function expandTokens(Collection $tokens): Collection
    {
        $synonyms = collect(config('knowledge.keyword_synonyms', []))
            ->filter(fn ($variants, $canonical) => is_string($canonical) && is_array($variants))
            ->mapWithKeys(function (array $variants, string $canonical): array {
                $normalizedCanonical = Str::of($canonical)->lower()->ascii()->value();
                $normalizedVariants = collect($variants)
                    ->filter(fn ($variant) => is_string($variant) && trim($variant) !== '')
                    ->map(fn (string $variant) => Str::of($variant)->lower()->ascii()->value())
                    ->push($normalizedCanonical)
                    ->unique()
                    ->values()
                    ->all();

                return [$normalizedCanonical => $normalizedVariants];
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
            ->values();
    }

    private function normalize(string $value): string
    {
        // Unifica composti tecnici con trattino in token unico:
        // qr-code -> qrcode, check-in -> checkin
        $value = preg_replace('/(?<=[\\pL\\pN])\\-(?=[\\pL\\pN])/u', '', $value) ?? $value;
        // Lo slash separa significati, quindi va trattato come separatore:
        // scritto/orale -> scritto orale
        $value = str_replace('/', ' ', $value);

        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->squish()
            ->value();
    }

    /**
     * @param  Collection<int, string>  $tokens
     */
    private function matches(string $haystack, Collection $tokens, string $needle): bool
    {
        return $this->matchAnalysis($haystack, $tokens, $needle)['matched'];
    }

    /**
     * @param  Collection<int, string>  $tokens
     * @return array{
     *   matched: bool,
     *   matched_tokens: int,
     *   total_tokens: int,
     *   match_ratio: float,
     *   direct_needle_match: bool,
     *   strong_term_match: bool,
     *   matched_terms: array<int, string>,
     *   query_terms: array<int, string>,
     *   strong_matched_terms: array<int, string>,
     *   keyword_score: float
     * }
     */
    private function matchAnalysis(string $haystack, Collection $tokens, string $needle): array
    {
        $queryTerms = $tokens->values()->all();

        if ($haystack === '') {
            return [
                'matched' => false,
                'matched_tokens' => 0,
                'total_tokens' => 0,
                'match_ratio' => 0.0,
                'direct_needle_match' => false,
                'strong_term_match' => false,
                'matched_terms' => [],
                'query_terms' => $queryTerms,
                'strong_matched_terms' => [],
                'keyword_score' => 0.0,
            ];
        }

        $directNeedleMatch = $needle !== '' && str_contains($haystack, $needle);
        if ($needle !== '' && str_contains($haystack, $needle)) {
            $totalTokens = $tokens->count();
            $matchedTokens = $totalTokens;
            $ratio = $totalTokens > 0 ? 1.0 : 0.0;
            $matchedTerms = $queryTerms;

            return [
                'matched' => true,
                'matched_tokens' => $matchedTokens,
                'total_tokens' => $totalTokens,
                'match_ratio' => $ratio,
                'direct_needle_match' => true,
                'strong_term_match' => false,
                'matched_terms' => $matchedTerms,
                'query_terms' => $queryTerms,
                'strong_matched_terms' => [],
                'keyword_score' => $this->computeKeywordScore(
                    matchRatio: $ratio,
                    matchedTokens: $matchedTokens,
                    directNeedleMatch: true,
                    strongTermMatch: false,
                ),
            ];
        }

        if ($tokens->isEmpty()) {
            return [
                'matched' => false,
                'matched_tokens' => 0,
                'total_tokens' => 0,
                'match_ratio' => 0.0,
                'direct_needle_match' => $directNeedleMatch,
                'strong_term_match' => false,
                'matched_terms' => [],
                'query_terms' => $queryTerms,
                'strong_matched_terms' => [],
                'keyword_score' => 0.0,
            ];
        }

        $totalTokens = $tokens->count();
        $matchedTerms = $tokens
            ->filter(fn (string $token): bool => str_contains($haystack, $token))
            ->values();
        $matchedTokens = $matchedTerms->count();

        $minTokensForRatio = max(1, (int) config('knowledge.keyword_min_tokens_for_ratio', 2));
        $minMatchRatio = max(0.0, min(1.0, (float) config('knowledge.keyword_min_match_ratio', 0.50)));
        $matchRatio = $totalTokens > 0 ? ($matchedTokens / $totalTokens) : 0.0;
        $strongTermMatch = false;
        $strongMatchedTerms = [];

        // Query molto corte: basta un match utile.
        if ($totalTokens < $minTokensForRatio) {
            return [
                'matched' => $matchedTokens > 0,
                'matched_tokens' => $matchedTokens,
                'total_tokens' => $totalTokens,
                'match_ratio' => round($matchRatio, 3),
                'direct_needle_match' => $directNeedleMatch,
                'strong_term_match' => false,
                'matched_terms' => $matchedTerms->all(),
                'query_terms' => $queryTerms,
                'strong_matched_terms' => [],
                'keyword_score' => $this->computeKeywordScore(
                    matchRatio: $matchRatio,
                    matchedTokens: $matchedTokens,
                    directNeedleMatch: $directNeedleMatch,
                    strongTermMatch: false,
                ),
            ];
        }

        if ($matchRatio >= $minMatchRatio) {
            return [
                'matched' => true,
                'matched_tokens' => $matchedTokens,
                'total_tokens' => $totalTokens,
                'match_ratio' => round($matchRatio, 3),
                'direct_needle_match' => $directNeedleMatch,
                'strong_term_match' => false,
                'matched_terms' => $matchedTerms->all(),
                'query_terms' => $queryTerms,
                'strong_matched_terms' => [],
                'keyword_score' => $this->computeKeywordScore(
                    matchRatio: $matchRatio,
                    matchedTokens: $matchedTokens,
                    directNeedleMatch: $directNeedleMatch,
                    strongTermMatch: false,
                ),
            ];
        }

        $strongTerms = collect(config('knowledge.keyword_strong_terms', []))
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => Str::of($item)->lower()->ascii()->value())
            ->unique()
            ->values();

        if ($strongTerms->isNotEmpty()) {
            $queryStrongTerms = $tokens
                ->filter(fn (string $token): bool => $strongTerms->contains($token))
                ->values();

            if ($queryStrongTerms->isNotEmpty()) {
                $strongMatchedTerms = $queryStrongTerms
                    ->filter(fn (string $token): bool => str_contains($haystack, $token))
                    ->values()
                    ->all();
                $hasStrongTermMatch = $strongMatchedTerms !== [];
                $strongTermMatch = $hasStrongTermMatch;

                // Boost moderato: se c'è almeno un termine forte, accetta con ratio leggermente più basso.
                $boostedRatioThreshold = max(0.0, $minMatchRatio - 0.15);
                if ($hasStrongTermMatch && $matchRatio >= $boostedRatioThreshold) {
                    return [
                        'matched' => true,
                        'matched_tokens' => $matchedTokens,
                        'total_tokens' => $totalTokens,
                        'match_ratio' => round($matchRatio, 3),
                        'direct_needle_match' => $directNeedleMatch,
                        'strong_term_match' => true,
                        'matched_terms' => $matchedTerms->all(),
                        'query_terms' => $queryTerms,
                        'strong_matched_terms' => $strongMatchedTerms,
                        'keyword_score' => $this->computeKeywordScore(
                            matchRatio: $matchRatio,
                            matchedTokens: $matchedTokens,
                            directNeedleMatch: $directNeedleMatch,
                            strongTermMatch: true,
                        ),
                    ];
                }
            }
        }

        return [
            'matched' => false,
            'matched_tokens' => $matchedTokens,
            'total_tokens' => $totalTokens,
            'match_ratio' => round($matchRatio, 3),
            'direct_needle_match' => $directNeedleMatch,
            'strong_term_match' => $strongTermMatch,
            'matched_terms' => $matchedTerms->all(),
            'query_terms' => $queryTerms,
            'strong_matched_terms' => $strongMatchedTerms,
            'keyword_score' => $this->computeKeywordScore(
                matchRatio: $matchRatio,
                matchedTokens: $matchedTokens,
                directNeedleMatch: $directNeedleMatch,
                strongTermMatch: $strongTermMatch,
            ),
        ];
    }

    private function computeKeywordScore(
        float $matchRatio,
        int $matchedTokens,
        bool $directNeedleMatch,
        bool $strongTermMatch
    ): float {
        $ranking = (array) config('knowledge.keyword_ranking', []);
        if (($ranking['enabled'] ?? true) !== true) {
            return round(max(0.0, min(1.0, $matchRatio)), 3);
        }

        $ratioWeight = max(0.0, (float) ($ranking['ratio_weight'] ?? 0.70));
        $directNeedleBonus = max(0.0, (float) ($ranking['direct_needle_bonus'] ?? 0.20));
        $strongTermBonus = max(0.0, (float) ($ranking['strong_term_bonus'] ?? 0.10));
        $matchedTokenBonus = max(0.0, (float) ($ranking['matched_token_bonus'] ?? 0.02));
        $maxTokenBonus = max(0.0, (float) ($ranking['max_token_bonus'] ?? 0.10));
        $maxScore = max(0.1, (float) ($ranking['max_score'] ?? 1.0));

        $tokenBonus = min($maxTokenBonus, $matchedTokens * $matchedTokenBonus);
        $score = ($matchRatio * $ratioWeight)
            + ($directNeedleMatch ? $directNeedleBonus : 0.0)
            + ($strongTermMatch ? $strongTermBonus : 0.0)
            + $tokenBonus;

        return round(max(0.0, min($maxScore, $score)), 3);
    }

    public function structuredLookup(string $query, ?string $tenantId = null): ?string
    {
        $normalized = $this->normalize($query);

        if ($normalized === '') {
            return null;
        }
        $tenantId = $tenantId ?? config('knowledge.default_tenant', 'demo');

        if ($this->structuredData === []) {
            $this->all($tenantId);
        }

        $tokens = $this->expandTokens($this->tokenize($normalized));
        $data = $this->structuredData;

        $responsabileInfo = Arr::get($data, 'contacts.responsabile_info_point');

        if ($responsabileInfo) {
            if ($tokens->contains('responsabile')) {
                $parts = [];
                if (! empty($responsabileInfo['name'])) {
                    $parts[] = sprintf('Responsabile info point: %s', $responsabileInfo['name']);
                }
                if (! empty($responsabileInfo['phone']) && $tokens->contains('phone')) {
                    $parts[] = sprintf('Telefono responsabile: %s', $responsabileInfo['phone']);
                }

                if (! empty($parts)) {
                    return implode(' – ', $parts);
                }
            }

            if ($tokens->contains('phone')) {
                if (! empty($responsabileInfo['phone'])) {
                    return sprintf(
                        'Numero di telefono del responsabile info point %s: %s',
                        $responsabileInfo['name'] ?? '',
                        $responsabileInfo['phone']
                    );
                }
            }
        }

        if ($tokens->contains('secretariat') || $tokens->contains('email')) {
            $secretariat = Arr::get($data, 'contacts.secretariat');

            if ($secretariat) {
                $parts = [];
                if (! empty($secretariat['email']) && ($tokens->contains('email') || $tokens->contains('secretariat'))) {
                    $parts[] = sprintf('Email segreteria: %s', $secretariat['email']);
                }
                if (! empty($secretariat['phone']) && ($tokens->contains('phone') || $tokens->contains('secretariat'))) {
                    $parts[] = sprintf('Telefono segreteria: %s', $secretariat['phone']);
                }

                if (! empty($parts)) {
                    return implode(' – ', $parts);
                }
            }
        }

        return null;
    }

    private function loadContent(string $relativePath, string $tenantId): ?string
    {
        $path = resource_path('knowledge/'.$tenantId.'/'.$relativePath);

        if (! File::exists($path)) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $data = json_decode(File::get($path), true);

            if ($data === null) {
                return null;
            }

            $this->structuredData = array_replace_recursive($this->structuredData, $data);

            $lines = $this->flattenJson($data);

            return implode("\n", array_map(
                fn ($pathKey, $value) => sprintf('%s: %s', $pathKey, $value),
                array_keys($lines),
                array_values($lines)
            ));
        }

        return File::get($path);
    }

    /**
     * @return array<int, string>
     */
    private function flattenJson(mixed $data, string $prefix = ''): array
    {
        if (is_array($data)) {
            $lines = [];
            foreach ($data as $key => $value) {
                $nextPrefix = $prefix !== '' ? $prefix.'.'.$key : (string) $key;
                $lines = array_merge($lines, $this->flattenJson($value, $nextPrefix));
            }

            return $lines;
        }

        if (is_scalar($data) || $data === null) {
            $value = is_bool($data) ? ($data ? 'true' : 'false') : (string) $data;

            return [$prefix => trim($value)];
        }

        return [];
    }
}
