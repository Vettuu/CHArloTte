<?php

namespace App\Knowledge;

use App\Models\KnowledgeChunk;
use App\Services\OpenAIEmbeddingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KnowledgeIndexer
{
    public function __construct(
        private readonly KnowledgeRepository $repository,
        private readonly OpenAIEmbeddingService $embeddings,
    ) {
    }

    public function rebuild(): int
    {
        DB::table('knowledge_chunks')->truncate();

        $documents = $this->repository->all();
        $count = 0;

        foreach ($documents as $document) {
            $chunks = $this->chunkDocument($document['content'] ?? '');

            foreach ($chunks as $position => $chunk) {
                $embedding = $this->embeddings->embedText($chunk);

                KnowledgeChunk::create([
                    'document_id' => $document['id'],
                    'content' => $chunk,
                    'metadata' => [
                        'title' => $document['title'] ?? null,
                        'tags' => $document['tags'] ?? [],
                        'position' => $position,
                    ],
                    'embedding' => $embedding,
                ]);

                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<int, string>
     */
    private function chunkDocument(string $content): array
    {
        $content = trim($content);

        if ($content === '') {
            return [];
        }

        $targetSize = (int) config('knowledge.chunk_size', 900);
        $overlap = (int) config('knowledge.chunk_overlap', 150);

        $paragraphs = collect(preg_split('/\n{2,}/', $content) ?: [])
            ->map(fn ($paragraph) => trim((string) $paragraph))
            ->filter();

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $candidate = $current === '' ? $paragraph : $current."\n\n".$paragraph;

            if (mb_strlen($candidate) > $targetSize && $current !== '') {
                $chunks[] = trim($current);

                if ($overlap > 0) {
                    $current = Str::of($current)
                        ->substr(-1 * $overlap)
                        ->append("\n\n".$paragraph)
                        ->value();
                } else {
                    $current = $paragraph;
                }
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter($chunks, fn ($chunk) => mb_strlen($chunk) >= 40));
    }
}
