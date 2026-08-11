<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ForwardScheme;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyHost>
 */
class ProxyHostFactory extends Factory
{
    protected $model = ProxyHost::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain' => fake()->unique()->domainName(),
            'forward_scheme' => ForwardScheme::Http,
            'forward_host' => fake()->ipv4(),
            'forward_port' => fake()->numberBetween(1024, 65535),
            'websockets_enabled' => false,
            'custom_config' => null,
            'ssl_certificate_id' => SslCertificate::factory(),
            'enabled' => true,
            'version' => 1,
            'created_by' => User::factory(),
        ];
    }
}
