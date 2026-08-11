<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AcmeChallengeStatus;
use Database\Factories\AcmeChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ssl_certificate_id',
    'domain',
    'token',
    'key_authorization',
    'status',
    'expires_at',
])]
class AcmeChallenge extends Model
{
    /** @use HasFactory<AcmeChallengeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<SslCertificate, $this>
     */
    public function sslCertificate(): BelongsTo
    {
        return $this->belongsTo(SslCertificate::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AcmeChallengeStatus::class,
            'expires_at' => 'datetime',
        ];
    }
}
