<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SslCertificateProvider;
use App\Enums\SslCertificateStatus;
use App\Enums\SslCertificateType;
use App\Models\SslCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SslCertificate>
 */
class SslCertificateFactory extends Factory
{
    protected $model = SslCertificate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = fake()->unique()->domainName();

        return [
            'type' => SslCertificateType::Acme,
            'primary_domain' => $domain,
            'domain_names' => [$domain],
            'provider' => SslCertificateProvider::LetsEncrypt,
            'status' => SslCertificateStatus::Valid,
            'storage_path' => "certificates/{$domain}.pem",
            'content_hash' => fake()->sha256(),
            'version' => 1,
            'issued_at' => now(),
            'expires_at' => now()->addMonths(3),
            'last_renewal_attempt_at' => null,
        ];
    }
}
