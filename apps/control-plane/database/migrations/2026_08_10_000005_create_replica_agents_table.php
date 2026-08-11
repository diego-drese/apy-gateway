<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('replica_agents', function (Blueprint $table) {
            $table->id();
            $table->string('hostname')->unique();
            $table->string('ip_address', 45);
            $table->string('agent_version');
            $table->string('nginx_version');
            $table->string('status')->default('offline');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('replica_agents');
    }
};
