<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * Define the model's default state.
     *
     * Does not set `subject_type`/`subject_id` — attach the subject explicitly in tests via
     * `AuditLog::factory()->for($model, 'subject')`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => 'login',
            'ip_address' => fake()->ipv4(),
            'metadata' => [],
        ];
    }
}
