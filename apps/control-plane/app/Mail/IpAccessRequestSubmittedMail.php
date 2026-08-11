<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\IpAccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class IpAccessRequestSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public IpAccessRequest $ipAccessRequest)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Solicitação de acesso — apy-gateway')
            ->view('emails.auth.ip-access-request-submitted', [
                'ipAccessRequest' => $this->ipAccessRequest,
                'approveUrl' => route('auth.ip-requests.approve', $this->ipAccessRequest->token),
            ]);
    }
}
