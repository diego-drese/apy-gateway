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
        Schema::create('acme_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ssl_certificate_id')->constrained('ssl_certificates')->cascadeOnDelete();
            $table->string('domain');
            $table->string('token')->unique();
            $table->text('key_authorization');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acme_challenges');
    }
};
