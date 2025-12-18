<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id')->nullable()->index();
            $table->string('mode')->default('audio');
            $table->string('status')->default('issued');
            $table->json('session_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_sessions');
    }
};
