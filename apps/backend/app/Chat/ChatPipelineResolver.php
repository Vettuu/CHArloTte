<?php

namespace App\Chat;

use App\Chat\Contracts\TokenPipeline;
use App\Chat\Exceptions\UnknownPipelineException;
use App\Chat\Pipelines\RealtimeTokenPipeline;
use App\Chat\Pipelines\TextTokenPipeline;

class ChatPipelineResolver
{
    /**
     * @var array<string, TokenPipeline>
     */
    private array $pipelines;

    public function __construct(
        RealtimeTokenPipeline $realtime,
        TextTokenPipeline $text,
    ) {
        $this->pipelines = [
            $realtime->key() => $realtime,
            $text->key() => $text,
        ];
    }

    public function resolveTokenPipeline(string $pipeline): TokenPipeline
    {
        $resolved = $this->pipelines[$pipeline] ?? null;

        if ($resolved === null) {
            throw new UnknownPipelineException("Unknown chat pipeline [{$pipeline}].");
        }

        return $resolved;
    }
}
