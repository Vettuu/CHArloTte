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
                'input' => mb_substr($text, 0, 8000),
            ])
            ->throw();

        $embedding = $response->json('data.0.embedding');

        if (! is_array($embedding)) {
            throw new \RuntimeException('Embedding response non valido');
        }

        return array_map('floatval', $embedding);
    }
}
