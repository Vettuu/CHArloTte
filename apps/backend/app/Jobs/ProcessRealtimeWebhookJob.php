<?php

namespace App\Jobs;

use App\Knowledge\KnowledgeService;
use App\Models\RealtimeSession;
use App\Services\OpenAIRealtimeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRealtimeWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly array $payload)
    {
    }

    public function handle(KnowledgeService $knowledge, OpenAIRealtimeService $service): void
    {
        Log::info('Processing realtime webhook event', $this->payload);

        if (! empty($this->payload['tool_name']) && ! empty($this->payload['session_id']) && ! empty($this->payload['call_id'])) {
            $response = $knowledge->handle($this->payload['tool_name'], $this->payload['payload'] ?? []);

            Log::info('Tool response prepared', $response->toArray());

            try {
                $service->sendFunctionResult(
                    sessionId: $this->payload['session_id'],
                    callId: $this->payload['call_id'],
                    text: $response->text,
                    data: $response->data,
                );
            } catch (\Throwable $exception) {
                Log::error('Failed to send function result to OpenAI', [
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if (! empty($this->payload['session_id'])) {
            $session = RealtimeSession::where('session_id', $this->payload['session_id'])->first();

            if ($session !== null) {
                $metadata = $session->metadata ?? [];
                $metadata['last_event'] = [
                    'event' => $this->payload['event'],
                    'payload' => $this->payload['payload'] ?? [],
                    'occurred_at' => now()->toIso8601String(),
                ];

                $session->update([
                    'status' => $this->payload['event'] ?? 'webhook_event',
                    'metadata' => $metadata,
                ]);
            }
        }
    }
}
