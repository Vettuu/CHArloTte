<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class KnowledgeRebuildTest extends TestCase
{
    public function test_requires_token(): void
    {
        config(['knowledge.rebuild_token' => 'secret']);

        $response = $this->postJson('/api/knowledge/rebuild');

        $response->assertForbidden();
    }

    public function test_runs_rebuild_when_token_matches(): void
    {
        config(['knowledge.rebuild_token' => 'secret']);

        Artisan::shouldReceive('call')
            ->once()
            ->with('knowledge:index');

        $response = $this->postJson('/api/knowledge/rebuild?token=secret');

        $response->assertOk()
            ->assertJson(['status' => 'ok']);
    }
}
