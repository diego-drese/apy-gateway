<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\IpAccessRequestStatus;
use App\Mail\IpAccessRequestSubmittedMail;
use App\Models\IpAccessRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RequestIpAccessAction
{
    public function handle(string $requestedEmail, string $ipAddress): IpAccessRequest
    {
        $ipAccessRequest = IpAccessRequest::query()->create([
            'ip_address' => $ipAddress,
            'requested_email' => $requestedEmail,
            'token' => Str::random(64),
            'status' => IpAccessRequestStatus::Pending,
            'expires_at' => now()->addHours(config('security.ip_access_request_ttl_hours')),
        ]);

        // SPEC.md §6.1: the approval link goes to the system administrator's e-mail
        // (configured via .env), never to the requester's own e-mail.
        Mail::to(config('security.admin_notification_email'))
            ->send(new IpAccessRequestSubmittedMail($ipAccessRequest));

        return $ipAccessRequest;
    }
}
