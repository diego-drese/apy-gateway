<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ForwardScheme;
use Database\Factories\ProxyHostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'domain',
    'forward_scheme',
    'forward_host',
    'forward_port',
    'websockets_enabled',
    'custom_config',
    'ssl_certificate_id',
    'enabled',
    'version',
    'created_by',
])]
class ProxyHost extends Model
{
    /** @use HasFactory<ProxyHostFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<SslCertificate, $this>
     */
    public function sslCertificate(): BelongsTo
    {
        return $this->belongsTo(SslCertificate::class);
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
            'forward_scheme' => ForwardScheme::class,
            'websockets_enabled' => 'boolean',
            'custom_config' => 'array',
            'enabled' => 'boolean',
            'version' => 'integer',
        ];
    }
}
