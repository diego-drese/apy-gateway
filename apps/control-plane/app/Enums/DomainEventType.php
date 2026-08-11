<?php

declare(strict_types=1);

namespace App\Enums;

enum DomainEventType: string
{
    case ProxyHostCreated = 'proxy_host.created';
    case ProxyHostUpdated = 'proxy_host.updated';
    case ProxyHostDeleted = 'proxy_host.deleted';
    case CertificateIssued = 'certificate.issued';
    case CertificateRenewed = 'certificate.renewed';
    case CertificateRevoked = 'certificate.revoked';

    // Fired when RequestCertificateIssuanceAction publishes HTTP-01 challenges to
    // `acme_challenges` — purely a latency optimization for the agent (it would otherwise
    // only pick them up on the next periodic reconciliation, up to ~5 min later). The
    // agent ignores subject_id for this event and always resyncs the whole pending set.
    case AcmeChallengeReady = 'acme_challenge.ready';
}
