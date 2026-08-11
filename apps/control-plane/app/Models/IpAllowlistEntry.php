<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IpAllowlistEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ip_address', 'label', 'approved_by', 'approved_at', 'expires_at'])]
class IpAllowlistEntry extends Model
{
    /** @use HasFactory<IpAllowlistEntryFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
