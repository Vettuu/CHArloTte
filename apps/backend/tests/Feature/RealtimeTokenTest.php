<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RealtimeTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_audio_token_and_persists_session(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'value' => 'ek_test',
                'session' => [
                    'id' => 'sess_test',
                    'output_modalities' => ['audio'],
                ],
            ], 201),
        ]);

        $response = $this->postJson('/api/realtime/token');

        $response->assertCreated()
            ->assertJsonPath('session.id', 'sess_test')
            ->assertJsonPath('session.output_modalities.0', 'audio');

        $this->assertDatabaseHas('realtime_sessions', [
            'session_id' => 'sess_test',
            'mode' => 'audio',
        ]);
    }

    public function test_it_validates_mode_parameter(): void
    {
        $response = $this->postJson('/api/realtime/token', ['mode' => 'invalid']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }
}
