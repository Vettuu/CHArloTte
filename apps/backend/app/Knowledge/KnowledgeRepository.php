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
    public function all(): Collection
    {
        $this->structuredData = [];

        $metadata = collect(json_decode(
            File::get(resource_path('knowledge/metadata.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        ));

        return $metadata->map(function (array $entry): array {
            $files = $entry['file'];
            $files = is_array($files) ? $files : [$files];

            $content = collect($files)
                ->map(fn ($file) => $this->loadContent($file))
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

    public function find(string $id): ?array
    {
        return $this->all()->firstWhere('id', $id);
    }

    /**
     * Simple keyword search across all knowledge documents.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function search(string $query): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $normalizedQuery = $this->normalize($query);

        if ($normalizedQuery === '') {
            $normalizedQuery = mb_strtolower($query);
        }
        $tokens = $this->expandTokens($this->tokenize($normalizedQuery));
        $documents = $this->all();

        $summaryMatches = $documents
            ->filter(function (array $document) use ($tokens, $normalizedQuery): bool {
                $haystack = $this->normalize($document['summary'] ?? '');

                return $this->matches($haystack, $tokens, $normalizedQuery);
            })
            ->map(function (array $document): array {
                $document['excerpt'] = trim($document['summary'] ?? '');

                return $document;
            });

        if ($summaryMatches->isNotEmpty()) {
            return $summaryMatches->values();
        }

        return $documents
            ->map(function (array $document) use ($tokens, $normalizedQuery): ?array {
                $content = $document['content'] ?? '';

                if ($content === '') {
                    return null;
                }

                $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

                foreach ($lines as $line) {
                    $normalizedLine = $this->normalize($line);

                    if ($this->matches($normalizedLine, $tokens, $normalizedQuery)) {
                        $document['excerpt'] = trim($line);

                        return $document;
                    }
                }

                $normalizedContent = $this->normalize($content);
                $position = mb_strpos($normalizedContent, $normalizedQuery);

                if ($position === false) {
                    return null;
                }

                $snippet = trim(mb_substr($content, max($position - 80, 0), 200));
                $document['excerpt'] = $snippet;

                return $document;
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function tokenize(string $value): Collection
    {
        return collect(preg_split('/\s+/', $value) ?: [])
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, string>  $tokens
     * @return Collection<int, string>
     */
    private function expandTokens(Collection $tokens): Collection
    {
        $synonyms = [
            'phone' => ['telefono', 'numero', 'cellulare', 'tel', 'phone'],
            'responsabile' => ['responsabile', 'referente', 'manager'],
            'email' => ['email', 'mail', 'indirizzo'],
            'name' => ['nome', 'name'],
            'secretariat' => ['segreteria', 'segreteria organizzativa', 'secretariat'],
        ];

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
        if ($haystack === '') {
            return false;
        }

        if ($tokens->isEmpty()) {
            return str_contains($haystack, $needle);
        }

        return $tokens->every(fn (string $token): bool => str_contains($haystack, $token));
    }

    public function structuredLookup(string $query): ?string
    {
        $normalized = $this->normalize($query);

        if ($normalized === '') {
            return null;
        }

        if ($this->structuredData === []) {
            $this->all();
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

    private function loadContent(string $relativePath): ?string
    {
        $path = resource_path('knowledge/'.$relativePath);

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
