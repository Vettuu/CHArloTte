<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('event_at')->nullable()->index();
            $table->string('session_id')->index();
            $table->string('tenant_id')->index();
            $table->string('pipeline')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->string('knowledge_tenant')->nullable()->index();
            $table->string('role', 32)->index();
            $table->string('source', 32)->nullable();
            $table->string('intent')->nullable()->index();
            $table->boolean('fallback')->nullable()->index();
            $table->boolean('contradiction_flag')->nullable()->index();
            $table->string('contradiction_type')->nullable();
            $table->unsignedSmallInteger('confidence_score')->nullable()->index();
            $table->string('confidence_bucket', 16)->nullable()->index();
            $table->unsignedSmallInteger('rag_hits')->nullable();
            $table->unsignedSmallInteger('accepted_hits_count')->nullable();
            $table->unsignedSmallInteger('diagnostic_hits_count')->nullable();
            $table->decimal('top_score', 8, 4)->nullable()->index();
            $table->string('semantic_level', 16)->nullable()->index();
            $table->unsignedSmallInteger('query_token_count')->nullable();
            $table->unsignedInteger('latency_ms')->nullable()->index();
            $table->unsignedInteger('reply_len')->nullable();
            $table->unsignedInteger('token_in')->nullable();
            $table->unsignedInteger('token_out')->nullable();
            $table->string('policy_path')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event_at']);
            $table->index(['tenant_id', 'pipeline', 'event_at']);
            $table->index(['tenant_id', 'session_id', 'event_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
