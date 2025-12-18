<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;

class OpenAIRealtimeService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * Request an ephemeral client secret for browser connections.
     *
     * @param  array<string, mixed>  $sessionOverrides
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function createClientSecret(array $sessionOverrides = [], array $metadata = []): array
    {
        $openaiConfig = config('services.openai');
        $sessionDefaults = config('realtime.session');

        $session = array_replace_recursive($sessionDefaults, $sessionOverrides);

        if (! empty($session['output_modalities'])) {
            $session['output_modalities'] = [Arr::first($session['output_modalities'])];
        }

        $payload = array_filter([
            'session' => $session,
            'metadata' => $metadata,
        ]);

        $request = $this->http->withToken($openaiConfig['api_key'])
            ->withHeaders(array_filter([
                'OpenAI-Organization' => Arr::get($openaiConfig, 'organization'),
                'OpenAI-Project' => Arr::get($openaiConfig, 'project'),
            ]))
            ->acceptJson()
            ->asJson()
            ->post('https://api.openai.com/v1/realtime/client_secrets', $payload)
            ->throw();

        return $request->json();
    }

    public function sendFunctionResult(string $sessionId, string $callId, string $text, array $data = []): void
    {
        $eventPayload = [
            'type' => 'conversation.item.create',
            'session_id' => $sessionId,
            'item' => [
                'type' => 'function_call_output',
                'call_id' => $callId,
                'output' => json_encode([
                    'text' => $text,
                    'data' => $data,
                ], JSON_THROW_ON_ERROR),
            ],
        ];

        $this->sendEvent($sessionId, $eventPayload);

        $this->sendEvent($sessionId, [
            'type' => 'response.create',
        ]);
    }

    private function sendEvent(string $sessionId, array $event): void
    {
        $openaiConfig = config('services.openai');

        $this->http->withToken($openaiConfig['api_key'])
            ->withHeaders(array_filter([
                'OpenAI-Organization' => Arr::get($openaiConfig, 'organization'),
                'OpenAI-Project' => Arr::get($openaiConfig, 'project'),
            ]))
            ->acceptJson()
            ->asJson()
            ->post("https://api.openai.com/v1/realtime/sessions/{$sessionId}/events", $event)
            ->throw();
    }
}
