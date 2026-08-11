<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SslCertificateProvider;
use App\Enums\SslCertificateStatus;
use App\Enums\SslCertificateType;
use Database\Factories\SslCertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type',
    'primary_domain',
    'domain_names',
    'provider',
    'status',
    'storage_path',
    'content_hash',
    'version',
    'issued_at',
    'expires_at',
    'last_renewal_attempt_at',
])]
class SslCertificate extends Model
{
    /** @use HasFactory<SslCertificateFactory> */
    use HasFactory;

    /**
     * @return HasMany<ProxyHost, $this>
     */
    public function proxyHosts(): HasMany
    {
        return $this->hasMany(ProxyHost::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SslCertificateType::class,
            'provider' => SslCertificateProvider::class,
            'status' => SslCertificateStatus::class,
            'domain_names' => 'array',
            'version' => 'integer',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_renewal_attempt_at' => 'datetime',
        ];
    }
}
