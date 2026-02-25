<?php

namespace App\Chat\Pipelines;

use App\Chat\Contracts\TokenPipeline;
use App\Services\OpenAIRealtimeService;

class RealtimeTokenPipeline implements TokenPipeline
{
    public function __construct(private readonly OpenAIRealtimeService $realtime)
    {
    }

    public function key(): string
    {
        return 'realtime';
    }

    public function issueToken(array $payload, string $tenantId, array $tenantConfig): array
    {
        $sessionOverrides = $payload['session'] ?? [];

        if (! empty($payload['mode'])) {
            $sessionOverrides['output_modalities'] = [$payload['mode']];
        }

        if (! empty($tenantConfig['instructions'])) {
            $sessionOverrides['instructions'] = $tenantConfig['instructions'];
        }

        $metadata = array_merge(
            ['tenant' => $tenantId, 'pipeline' => $this->key()],
            $payload['metadata'] ?? []
        );

        $result = $this->realtime->createClientSecret($sessionOverrides);

        return [
            'result' => $result,
            'mode' => (string) data_get($result, 'session.output_modalities.0', config('realtime.default_mode', 'audio')),
            'metadata' => $metadata,
        ];
    }
}
