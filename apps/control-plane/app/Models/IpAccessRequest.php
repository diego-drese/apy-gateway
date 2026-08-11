<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IpAccessRequestStatus;
use Database\Factories\IpAccessRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['ip_address', 'requested_email', 'token', 'status', 'approved_at', 'expires_at'])]
#[Hidden(['token'])]
class IpAccessRequest extends Model
{
    /** @use HasFactory<IpAccessRequestFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IpAccessRequestStatus::class,
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
