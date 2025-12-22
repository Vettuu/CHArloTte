<?php

namespace App\Knowledge;

use App\Models\KnowledgeChunk;
use App\Services\OpenAIEmbeddingService;
use App\Support\TextNormalizer;
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

            $batchSize = max((int) config('knowledge.index_batch_size', 8), 1);
            $batches = array_chunk($chunks, $batchSize, true);

            foreach ($batches as $batch) {
                $batchChunks = array_values($batch);
                $batchPositions = array_keys($batch);
                $normalized = array_map(
                    fn ($chunk) => TextNormalizer::forEmbedding($chunk),
                    $batchChunks
                );
                $embeddings = $this->embeddings->embedBatch($normalized);

                foreach ($batchChunks as $index => $chunk) {
                    $position = $batchPositions[$index];
                    $embedding = $embeddings[$index] ?? [];

                    KnowledgeChunk::create([
                        'document_id' => $document['id'],
                        'content' => $chunk,
                        'metadata' => [
                            'title' => $document['title'] ?? null,
                            'tags' => $document['tags'] ?? [],
                            'position' => $position,
                        ],
                        'embedding' => $embedding,
                        'embedding_norm' => $this->vectorNorm($embedding),
                    ]);

                    $count++;
                }
            }
        }

        return $count;
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
