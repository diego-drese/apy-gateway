<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IpAccessRequestStatus;
use App\Models\IpAccessRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IpAccessRequest>
 */
class IpAccessRequestFactory extends Factory
{
    protected $model = IpAccessRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ip_address' => fake()->ipv4(),
            'requested_email' => fake()->safeEmail(),
            'token' => Str::random(40),
            'status' => IpAccessRequestStatus::Pending,
            'approved_at' => null,
            'expires_at' => now()->addDay(),
        ];
    }
}
