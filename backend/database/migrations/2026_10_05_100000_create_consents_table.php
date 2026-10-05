<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('policy_version', 20);
            $table->string('granted_by', 10);
            $table->string('channel', 20);
            $table->string('destination_masked', 100)->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'type', 'granted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
