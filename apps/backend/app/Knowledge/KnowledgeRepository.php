<?php

namespace App\Knowledge;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class KnowledgeRepository
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        $metadata = collect(json_decode(
            File::get(resource_path('knowledge/metadata.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        ));

        return $metadata->map(function (array $entry): array {
            $contentPath = resource_path('knowledge/'.$entry['file']);

            return [
                'id' => $entry['id'],
                'title' => $entry['title'],
                'tags' => $entry['tags'],
                'summary' => $entry['summary'],
                'content' => File::exists($contentPath) ? File::get($contentPath) : null,
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
        $query = mb_strtolower($query);

        return $this->all()->filter(function (array $document) use ($query): bool {
            return str_contains(mb_strtolower($document['content'] ?? ''), $query)
                || str_contains(mb_strtolower($document['summary'] ?? ''), $query);
        });
    }
}
