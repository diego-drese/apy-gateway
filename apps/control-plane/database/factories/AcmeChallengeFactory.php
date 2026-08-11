<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AcmeChallengeStatus;
use App\Models\AcmeChallenge;
use App\Models\SslCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcmeChallenge>
 */
class AcmeChallengeFactory extends Factory
{
    protected $model = AcmeChallenge::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ssl_certificate_id' => SslCertificate::factory(),
            'domain' => fake()->unique()->domainName(),
            'token' => fake()->unique()->regexify('[A-Za-z0-9_-]{43}'),
            'key_authorization' => fake()->regexify('[A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43}'),
            'status' => AcmeChallengeStatus::Pending,
            'expires_at' => now()->addHours(1),
        ];
    }
}
