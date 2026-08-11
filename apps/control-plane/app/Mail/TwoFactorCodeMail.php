<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TwoFactorCodeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Seu código de verificação — apy-gateway')
            ->view('emails.auth.two-factor-code', [
                'code' => $this->code,
                'ttlMinutes' => config('security.two_factor_code_ttl_minutes'),
            ]);
    }
}
