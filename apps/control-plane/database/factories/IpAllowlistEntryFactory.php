<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IpAllowlistEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpAllowlistEntry>
 */
class IpAllowlistEntryFactory extends Factory
{
    protected $model = IpAllowlistEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ip_address' => fake()->ipv4(),
            'label' => fake()->words(2, true),
            'approved_by' => User::factory(),
            'approved_at' => now(),
            'expires_at' => null,
        ];
    }
}
