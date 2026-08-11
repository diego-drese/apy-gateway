<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DomainEventType;
use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DomainEvent>
 */
class DomainEventFactory extends Factory
{
    protected $model = DomainEvent::class;

    /**
     * Define the model's default state.
     *
     * Does not set `subject_type`/`subject_id` — attach the subject explicitly in tests via
     * `DomainEvent::factory()->for($model, 'subject')`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => DomainEventType::ProxyHostCreated,
            'payload' => ['version' => 1],
            'created_by' => User::factory(),
        ];
    }
}
