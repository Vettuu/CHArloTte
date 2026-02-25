<?php

namespace App\Chat\Pipelines;

use App\Chat\Contracts\TokenPipeline;
use App\Chat\Exceptions\UnsupportedPipelineOperationException;

class TextTokenPipeline implements TokenPipeline
{
    public function key(): string
    {
        return 'text';
    }

    public function issueToken(array $payload, string $tenantId, array $tenantConfig): array
    {
        throw new UnsupportedPipelineOperationException(
            'The text pipeline does not use realtime token issuance. Use the dedicated text chat endpoint.'
        );
    }
}
