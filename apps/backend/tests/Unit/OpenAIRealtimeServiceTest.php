<?php

namespace Tests\Unit;

use App\Services\OpenAIRealtimeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIRealtimeServiceTest extends TestCase
{
    public function test_send_function_result_posts_events(): void
    {
        Config::set('services.openai.api_key', 'test');
        Config::set('services.openai.organization', null);
        Config::set('services.openai.project', null);

        Http::fake();

        $service = app(OpenAIRealtimeService::class);
        $service->sendFunctionResult('sess_123', 'call_456', 'Test response', ['foo' => 'bar']);

        Http::assertSentCount(2);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.openai.com/v1/realtime/sessions/sess_123/events'
                && isset($data['type']);
        });
    }
}
