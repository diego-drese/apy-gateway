<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditLogAction: string
{
    case LoginFailed = 'login.failed';
    case LoginPasswordVerified = 'login.password_verified';
    case TwoFactorVerified = 'two_factor.verified';
    case TwoFactorFailed = 'two_factor.failed';
    case IpAccessRequestApproved = 'ip_access_request.approved';
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserDeleted = 'user.deleted';
    case IpAllowlistEntryCreated = 'ip_allowlist_entry.created';
    case IpAllowlistEntryDeleted = 'ip_allowlist_entry.deleted';
}
