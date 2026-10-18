{{-- Single place that maps SslCertificateStatus to a label and badge colour. --}}
@props(['status'])

@php
    [$label, $variant] = match ($status) {
        \App\Enums\SslCertificateStatus::Pending => ['Emitindo…', 'badge-accent'],
        \App\Enums\SslCertificateStatus::Valid => ['Válido', 'badge-success'],
        \App\Enums\SslCertificateStatus::Expiring => ['Expirando', 'badge-warning'],
        \App\Enums\SslCertificateStatus::Expired => ['Expirado', 'badge-danger'],
        \App\Enums\SslCertificateStatus::Error => ['Erro', 'badge-danger'],
    };
@endphp

<span {{ $attributes->merge(['class' => "badge {$variant}"]) }}>{{ $label }}</span>
