<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;

class OpenAIEmbeddingService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @return array<int, float>
     *
     * @throws RequestException
     */
    public function embedText(string $text): array
    {
        return $this->embedBatch([$text])[0] ?? [];
    }

    /**
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $inputs): array
    {
        $inputs = array_values(array_filter(array_map(
            fn ($text) => mb_substr((string) $text, 0, 8000),
            $inputs
        )));

        if ($inputs === []) {
            return [];
        }

        $openaiConfig = config('services.openai');
        $model = config('knowledge.embedding_model');

        if (empty($model)) {
            throw new \RuntimeException('OPENAI_EMBEDDING_MODEL non configurato.');
        }

        $response = $this->http->withToken($openaiConfig['api_key'])
            ->acceptJson()
            ->asJson()
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => $model,
                'input' => $inputs,
            ])
            ->throw();

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new \RuntimeException('Embedding response non valido');
        }

        return collect($data)
            ->map(fn ($item) => array_map('floatval', $item['embedding'] ?? []))
            ->values()
            ->toArray();
    }
}
