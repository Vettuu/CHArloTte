<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id')->index();
            $table->string('tenant_id')->index();
            $table->string('message_id')->nullable()->index();
            $table->string('role');
            $table->longText('content');
            $table->string('source')->default('text');
            $table->unsignedInteger('tokens_est')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
