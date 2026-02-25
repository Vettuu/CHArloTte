<?php

namespace App\Chat\Contracts;

interface TokenPipeline
{
    public function key(): string;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $tenantConfig
     * @return array{result: array<string, mixed>, mode: string, metadata: array<string, mixed>}
     */
    public function issueToken(array $payload, string $tenantId, array $tenantConfig): array;
}
