<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Import\Npm\ImportNpmProxyHostsAction;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Thin orchestration only — reading the inputs and printing the report. The migration rules live
 * in ImportNpmProxyHostsAction. Step-by-step usage: docs/deploy/swarm.md ("Migração do NPM").
 */
class ImportNpmProxyHostsCommand extends Command
{
    protected $signature = 'gateway:import-npm
        {hosts-json : Arquivo com a saída de GET /api/nginx/proxy-hosts do NPM}
        {--certificates-dir= : Diretório com npm-{id}/fullchain.pem e privkey.pem copiados do NPM}
        {--as= : E-mail do usuário registrado como autor das alterações}
        {--dry-run : Só mostra o que seria feito, sem gravar nada}
        {--include-unsupported : Importa também hosts com access list/advanced config/custom locations, SEM esses recursos}';

    protected $description = 'Importa proxy hosts (e seus certificados) de uma instalação do Nginx Proxy Manager';

    public function handle(ImportNpmProxyHostsAction $import): int
    {
        $raw = @file_get_contents((string) $this->argument('hosts-json'));
        $hosts = $raw === false ? null : json_decode($raw, true);

        if (! is_array($hosts) || ! array_is_list($hosts)) {
            $this->error('Arquivo de hosts ilegível ou não é a lista JSON retornada por GET /api/nginx/proxy-hosts.');

            return self::FAILURE;
        }

        $actor = User::query()->where('email', (string) $this->option('as'))->first();
        if ($actor === null) {
            $this->error('Informe --as=<e-mail de um usuário existente> (autor registrado nas alterações).');

            return self::FAILURE;
        }

        $report = $import->handle(
            $hosts,
            (string) ($this->option('certificates-dir') ?? ''),
            $actor,
            (bool) $this->option('dry-run'),
            (bool) $this->option('include-unsupported'),
        );

        $verb = $this->option('dry-run') ? 'Seriam criados' : 'Criados';
        $this->info("{$verb}: ".count($report->created));
        foreach ($report->created as $domain) {
            // The suffix keeps disabled hosts out of deploy/swarm/compare-with-npm.sh, which only
            // reads bare "+ domain" lines: neither side serves them, so comparing would be noise.
            $suffix = in_array($domain, $report->createdDisabled, true) ? ' (desabilitado, como no NPM)' : '';
            $this->line("  + {$domain}{$suffix}");
        }

        $this->info('Já existentes (ignorados): '.count($report->alreadyExisting));
        foreach ($report->alreadyExisting as $domain) {
            $this->line("  = {$domain}");
        }

        foreach ($report->warnings as $domain => $features) {
            $this->warn("  ! {$domain}: não reproduzido aqui: ".implode(', ', $features));
        }

        if ($report->hasSkipped()) {
            $this->error('Pulados: '.count($report->skipped));
            foreach ($report->skipped as $domain => $reason) {
                $this->line("  - {$domain}: {$reason}");
            }
        }

        $this->info('Certificados enviados: '.count($report->certificatesUploaded));

        // Non-zero so a scripted migration stops and someone looks at what was left behind.
        return $report->hasSkipped() ? self::FAILURE : self::SUCCESS;
    }
}
