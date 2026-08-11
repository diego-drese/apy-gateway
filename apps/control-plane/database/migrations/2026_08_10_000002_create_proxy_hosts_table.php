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
        Schema::create('proxy_hosts', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('forward_scheme');
            $table->string('forward_host');
            $table->unsignedSmallInteger('forward_port');
            $table->boolean('websockets_enabled')->default(false);
            $table->json('custom_config')->nullable();
            $table->foreignId('ssl_certificate_id')->nullable()
                ->constrained('ssl_certificates')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proxy_hosts');
    }
};
