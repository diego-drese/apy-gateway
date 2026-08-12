<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReplicaAgentStatus;
use App\Models\ReplicaAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplicaAgent>
 */
class ReplicaAgentFactory extends Factory
{
    protected $model = ReplicaAgent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hostname' => fake()->unique()->domainWord() . '.internal',
            'ip_address' => fake()->ipv4(),
            'agent_version' => '0.1.0',
            'nginx_version' => '1.27.0',
            'status' => ReplicaAgentStatus::Online,
            'synced_domains_count' => fake()->numberBetween(0, 20),
            'last_heartbeat_at' => now(),
        ];
    }
}
