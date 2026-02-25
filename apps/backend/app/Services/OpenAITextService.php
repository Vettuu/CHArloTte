<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;

class OpenAITextService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @throws RequestException
     *
     * @param array<string, mixed>|null $webSearch
     *
     * @return array{
     *   response: array<string, mixed>,
     *   text: string,
     *   sources: array<int, array{title: ?string, url: ?string}>
     * }
     */
    public function respond(
        string $model,
        string $instructions,
        string $input,
        float $temperature,
        int $maxOutputTokens,
        ?array $webSearch = null,
    ): array {
        $openaiConfig = config('services.openai');

        $payload = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => $input,
            'temperature' => $temperature,
            'max_output_tokens' => $maxOutputTokens,
        ];

        if (($webSearch['enabled'] ?? false) === true) {
            $tool = [
                'type' => 'web_search',
                'search_context_size' => (string) ($webSearch['search_context_size'] ?? 'medium'),
            ];

            $allowedDomains = $webSearch['allowed_domains'] ?? [];
            if (is_array($allowedDomains) && $allowedDomains !== []) {
                $tool['filters'] = [
                    'allowed_domains' => array_values($allowedDomains),
                ];
            }

            $payload['tools'] = [$tool];
        }

        $response = $this->http->withToken($openaiConfig['api_key'])
            ->withHeaders(array_filter([
                'OpenAI-Organization' => Arr::get($openaiConfig, 'organization'),
                'OpenAI-Project' => Arr::get($openaiConfig, 'project'),
            ]))
            ->acceptJson()
            ->asJson()
            ->post('https://api.openai.com/v1/responses', $payload)
            ->throw();

        $json = $response->json();

        return [
            'response' => is_array($json) ? $json : [],
            'text' => $this->extractText(is_array($json) ? $json : []),
            'sources' => $this->extractSources(is_array($json) ? $json : []),
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractText(array $response): string
    {
        $direct = data_get($response, 'output_text');
        if (is_string($direct) && trim($direct) !== '') {
            return trim($direct);
        }

        $texts = [];

        $output = data_get($response, 'output', []);
        if (! is_array($output)) {
            return '';
        }

        foreach ($output as $item) {
            $content = data_get($item, 'content', []);
            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                $text = data_get($part, 'text');
                if (is_string($text) && trim($text) !== '') {
                    $texts[] = trim($text);
                }
            }
        }

        return trim(implode("\n\n", $texts));
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, array{title: ?string, url: ?string}>
     */
    private function extractSources(array $response): array
    {
        $sources = [];

        $topLevel = data_get($response, 'sources', []);
        if (is_array($topLevel)) {
            foreach ($topLevel as $source) {
                $url = data_get($source, 'url');
                $title = data_get($source, 'title');
                if (is_string($url) || is_string($title)) {
                    $sources[] = [
                        'title' => is_string($title) ? $title : null,
                        'url' => is_string($url) ? $url : null,
                    ];
                }
            }
        }

        $output = data_get($response, 'output', []);
        if (is_array($output)) {
            foreach ($output as $item) {
                $content = data_get($item, 'content', []);
                if (! is_array($content)) {
                    continue;
                }

                foreach ($content as $part) {
                    $annotations = data_get($part, 'annotations', []);
                    if (! is_array($annotations)) {
                        continue;
                    }

                    foreach ($annotations as $annotation) {
                        $url = data_get($annotation, 'url') ?? data_get($annotation, 'source.url');
                        $title = data_get($annotation, 'title') ?? data_get($annotation, 'source.title');
                        if (is_string($url) || is_string($title)) {
                            $sources[] = [
                                'title' => is_string($title) ? $title : null,
                                'url' => is_string($url) ? $url : null,
                            ];
                        }
                    }
                }
            }
        }

        return collect($sources)
            ->filter(fn (array $source): bool => ($source['title'] ?? null) !== null || ($source['url'] ?? null) !== null)
            ->unique(fn (array $source): string => ($source['url'] ?? '').'|'.($source['title'] ?? ''))
            ->values()
            ->toArray();
    }
}
