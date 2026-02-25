<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatRespondTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_text_response_for_text_pipeline_tenant(): void
    {
        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'data' => [[
                    'embedding' => [0.1, 0.2, 0.3],
                ]],
            ], 200),
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => 'Risposta test da pipeline text.',
            ], 200),
        ]);

        $response = $this->postJson('/api/chat/respond', [
            'tenant' => 'charlotte_text',
            'message' => 'accredito con ipad qrcode',
        ]);

        $response->assertOk()
            ->assertJsonPath('tenant.id', 'charlotte_text')
            ->assertJsonPath('tenant.pipeline', 'text');

        $json = $response->json();
        $this->assertIsString($json['reply'] ?? null);
        $this->assertNotSame('', trim((string) ($json['reply'] ?? '')));

        if (($json['model'] ?? null) === null) {
            $this->assertTrue((bool) ($json['fallback'] ?? false));
            $this->assertSame('strict_fallback', $json['policy_path'] ?? null);
        } else {
            $this->assertSame(config('tenants.map.charlotte_text.chat_model'), $json['model']);
            $this->assertSame('Risposta test da pipeline text.', $json['reply']);
        }
    }

    public function test_it_rejects_non_text_pipeline_tenant(): void
    {
        $response = $this->postJson('/api/chat/respond', [
            'tenant' => 'charlotte',
            'message' => 'Ciao',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('pipeline', 'realtime');
    }
}
