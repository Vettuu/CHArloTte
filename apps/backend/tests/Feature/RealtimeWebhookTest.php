<?php

namespace Tests\Feature;

use App\Jobs\ProcessRealtimeWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RealtimeWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_webhook_and_dispatches_job(): void
    {
        Queue::fake();

        $payload = [
            'event' => 'response.completed',
            'session_id' => 'sess_123',
            'payload' => [
                'content' => 'hello',
            ],
        ];

        $response = $this->postJson('/api/realtime/invoke-tool', $payload);

        $response->assertAccepted();

        Queue::assertPushed(ProcessRealtimeWebhookJob::class, function (ProcessRealtimeWebhookJob $job) use ($payload): bool {
            return $job->payload['event'] === $payload['event'];
        });
    }
}
