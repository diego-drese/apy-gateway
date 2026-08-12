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
        Schema::table('replica_agents', function (Blueprint $table) {
            // Nullable on purpose: null means "never received a valid heartbeat payload yet",
            // never conflate that with a real zero (SPEC.md §12 — Fase 10).
            $table->unsignedInteger('synced_domains_count')->nullable()->after('nginx_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('replica_agents', function (Blueprint $table) {
            $table->dropColumn('synced_domains_count');
        });
    }
};
