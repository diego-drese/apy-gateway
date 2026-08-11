<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainEventType;
use Database\Factories\DomainEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['type', 'subject_type', 'subject_id', 'payload', 'created_by'])]
class DomainEvent extends Model
{
    /** @use HasFactory<DomainEventFactory> */
    use HasFactory;

    /**
     * domain_events is append-only — events are never edited after being recorded.
     */
    public const ?string UPDATED_AT = null;

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DomainEventType::class,
            'payload' => 'array',
        ];
    }
}
