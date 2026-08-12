<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IpAllowlistEntry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Fase 11: without this, `docker compose up -d` produces a running app nobody can log into —
 * Fase 8's UI has no self-registration route, `CreateUserAction` is admin-only and requires an
 * existing actor for its audit log entry, which doesn't fit a from-scratch install. Idempotent:
 * safe to run on every `scripts/install.sh` invocation, not just the first.
 */
class BootstrapAdminCommand extends Command
{
    protected $signature = 'gateway:bootstrap-admin
        {--ip=127.0.0.1 : IP address to allowlist for authenticated routes}
        {--email=admin@example.com : Email for the first admin user}
        {--name=Admin : Name for the first admin user}';

    protected $description = 'Allowlist an IP and create the first admin user if none exists yet';

    public function handle(): int
    {
        $ip = $this->option('ip');

        // Always ensured, even on reruns — e.g. install.sh discovering the real
        // container-observed IP differs from the 127.0.0.1 default (EnsureIpIsAllowlisted has no
        // TrustProxies configured, so the IP the app sees depends on the Docker network setup).
        IpAllowlistEntry::query()->firstOrCreate(
            ['ip_address' => $ip],
            ['label' => 'bootstrap', 'approved_at' => now()],
        );
        $this->info("IP liberado: {$ip}");

        if (User::query()->exists()) {
            $this->info('Já existe usuário — nada a fazer.');

            return self::SUCCESS;
        }

        $email = $this->option('email');
        $user = User::query()->create([
            'name' => $this->option('name'),
            'email' => $email,
            // Unusable placeholder — same convention as CreateUserAction: the account can't be
            // logged into until the reset link below is used, never a real password directly.
            'password' => Hash::make(Str::random(64)),
        ]);

        Password::sendResetLink(['email' => $email]);

        $this->info("Admin criado: {$email}. Verifique o Mailpit (ou o MAIL_MAILER configurado) pra definir a senha.");

        return self::SUCCESS;
    }
}
