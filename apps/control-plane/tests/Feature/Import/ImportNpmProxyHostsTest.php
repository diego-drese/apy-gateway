<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Actions\Import\Npm\ImportNpmProxyHostsAction;
use App\Enums\SslCertificateType;
use App\Models\DomainEvent;
use App\Models\ProxyHost;
use App\Models\SslCertificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportNpmProxyHostsTest extends TestCase
{
    use RefreshDatabase;

    private string $certificatesDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');
        Redis::shouldReceive('connection')->with('events')->andReturnSelf();
        Redis::shouldReceive('publish')->zeroOrMoreTimes();

        $this->certificatesDir = sys_get_temp_dir().'/npm-import-'.uniqid();
        mkdir($this->certificatesDir);
    }

    /**
     * Shape copied from a real NPM 2.x `GET /api/nginx/proxy-hosts` response (booleans as 0/1
     * like older versions return them, to cover the tolerant parsing).
     *
     * @return array<string, mixed>
     */
    private function npmHost(array $overrides = []): array
    {
        return [
            'id' => 1,
            'created_on' => '2025-01-10 12:00:00',
            'modified_on' => '2025-01-10 12:00:00',
            'owner_user_id' => 1,
            'domain_names' => ['app.example.com', 'www.app.example.com'],
            'forward_host' => 'mystack_web',
            'forward_port' => 8080,
            'access_list_id' => 0,
            'certificate_id' => 0,
            'ssl_forced' => 0,
            'caching_enabled' => 0,
            'block_exploits' => 0,
            'advanced_config' => '',
            'meta' => ['letsencrypt_agree' => false, 'dns_challenge' => false, 'nginx_online' => true, 'nginx_err' => null],
            'allow_websocket_upgrade' => 1,
            'http2_support' => 0,
            'forward_scheme' => 'http',
            'enabled' => 1,
            'locations' => [],
            'hsts_enabled' => 0,
            'hsts_subdomains' => 0,
            ...$overrides,
        ];
    }

    /**
     * Writes a self-signed certificate in NPM's on-disk layout (npm-{id}/fullchain.pem + privkey.pem).
     *
     * @param list<string> $domains
     */
    private function writeNpmCertificate(int $npmCertificateId, array $domains, int $days = 60): void
    {
        $confPath = tempnam(sys_get_temp_dir(), 'san');
        $san = implode(',', array_map(fn (string $d) => "DNS:{$d}", $domains));
        file_put_contents($confPath, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName={$san}\n");
        $config = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'v3', 'config' => $confPath];

        $key = openssl_pkey_new($config);
        $csr = openssl_csr_new(['commonName' => $domains[0]], $key, $config);
        $x509 = openssl_csr_sign($csr, null, $key, $days, $config, random_int(1, PHP_INT_MAX));
        openssl_x509_export($x509, $certificatePem);
        openssl_pkey_export($key, $privateKeyPem);
        unlink($confPath);

        $dir = "{$this->certificatesDir}/npm-{$npmCertificateId}";
        mkdir($dir);
        file_put_contents("{$dir}/fullchain.pem", $certificatePem);
        file_put_contents("{$dir}/privkey.pem", $privateKeyPem);
    }

    private function import(array $hosts, bool $dryRun = false, bool $includeUnsupported = false)
    {
        return app(ImportNpmProxyHostsAction::class)->handle(
            $hosts,
            $this->certificatesDir,
            User::factory()->create(),
            $dryRun,
            $includeUnsupported,
        );
    }

    public function test_each_npm_domain_becomes_its_own_proxy_host_with_the_same_upstream(): void
    {
        $report = $this->import([$this->npmHost()]);

        $this->assertSame(['app.example.com', 'www.app.example.com'], $report->created);
        $this->assertSame(2, ProxyHost::query()->count());

        $host = ProxyHost::query()->where('domain', 'www.app.example.com')->sole();
        $this->assertSame('mystack_web', $host->forward_host);
        $this->assertSame(8080, $host->forward_port);
        $this->assertTrue($host->websockets_enabled);
        $this->assertTrue($host->enabled);
        $this->assertNull($host->ssl_certificate_id);

        // Goes through CreateProxyHostAction, so agents are notified like any API change.
        $this->assertSame(2, DomainEvent::query()->count());
    }

    public function test_a_shared_npm_certificate_is_uploaded_once_and_attached_to_every_domain(): void
    {
        $this->writeNpmCertificate(7, ['app.example.com', 'www.app.example.com']);

        $report = $this->import([$this->npmHost(['certificate_id' => 7, 'ssl_forced' => 1])]);

        $this->assertSame([7], $report->certificatesUploaded);
        $certificate = SslCertificate::query()->where('type', SslCertificateType::Uploaded)->sole();
        $this->assertSame(['app.example.com', 'www.app.example.com'], $certificate->domain_names);
        $this->assertSame(2, ProxyHost::query()->where('ssl_certificate_id', $certificate->id)->count());
        Storage::disk('minio')->assertExists("{$certificate->storage_path}.key");
        $this->assertSame([], $report->warnings);
    }

    public function test_an_identical_certificate_already_stored_is_reused_instead_of_uploaded_again(): void
    {
        $this->writeNpmCertificate(7, ['app.example.com', 'www.app.example.com']);
        $existing = SslCertificate::factory()->create([
            'content_hash' => hash('sha256', file_get_contents("{$this->certificatesDir}/npm-7/fullchain.pem")),
        ]);

        $report = $this->import([$this->npmHost(['certificate_id' => 7, 'ssl_forced' => 1])]);

        $this->assertSame([], $report->certificatesUploaded);
        $this->assertSame(2, ProxyHost::query()->where('ssl_certificate_id', $existing->id)->count());
    }

    public function test_hosts_with_access_control_or_custom_nginx_are_skipped_by_default(): void
    {
        $report = $this->import([
            $this->npmHost(['id' => 1, 'domain_names' => ['acl.example.com'], 'access_list_id' => 3]),
            $this->npmHost(['id' => 2, 'domain_names' => ['adv.example.com'], 'advanced_config' => "location /x { deny all; }"]),
            $this->npmHost(['id' => 3, 'domain_names' => ['loc.example.com'], 'locations' => [['path' => '/api', 'forward_host' => 'api']]]),
        ]);

        $this->assertSame([], $report->created);
        $this->assertStringContainsString('access list', $report->skipped['acl.example.com']);
        $this->assertStringContainsString('advanced config', $report->skipped['adv.example.com']);
        $this->assertStringContainsString('custom locations', $report->skipped['loc.example.com']);
        $this->assertSame(0, ProxyHost::query()->count());
    }

    public function test_include_unsupported_imports_them_and_reports_what_was_not_reproduced(): void
    {
        $report = $this->import([
            $this->npmHost(['domain_names' => ['acl.example.com'], 'access_list_id' => 3, 'block_exploits' => 1, 'hsts_enabled' => 1]),
        ], includeUnsupported: true);

        $this->assertSame(['acl.example.com'], $report->created);
        $this->assertContains('access list (autenticação/IP do NPM)', $report->warnings['acl.example.com']);
        $this->assertContains('block common exploits', $report->warnings['acl.example.com']);
        $this->assertContains('HSTS', $report->warnings['acl.example.com']);
    }

    public function test_a_host_whose_certificate_files_are_missing_is_skipped(): void
    {
        $report = $this->import([$this->npmHost(['domain_names' => ['tls.example.com'], 'certificate_id' => 99, 'ssl_forced' => 1])]);

        $this->assertStringContainsString('npm-99', $report->skipped['tls.example.com']);
        $this->assertSame(0, ProxyHost::query()->count());
    }

    public function test_an_expired_certificate_is_refused_and_its_domains_skipped(): void
    {
        $this->writeNpmCertificate(5, ['old.example.com'], days: 1);
        $this->travel(2)->days();

        $report = $this->import([$this->npmHost(['domain_names' => ['old.example.com'], 'certificate_id' => 5, 'ssl_forced' => 1])]);

        $this->assertStringContainsString('recusado', $report->skipped['old.example.com']);
        $this->assertSame(0, ProxyHost::query()->count());
    }

    public function test_untrusted_values_from_npm_are_validated_like_api_input(): void
    {
        $report = $this->import([
            $this->npmHost(['id' => 1, 'domain_names' => ['*.wildcard.example.com']]),
            $this->npmHost(['id' => 2, 'domain_names' => ['inject.example.com'], 'forward_host' => 'x; include /etc/passwd']),
            $this->npmHost(['id' => 3, 'domain_names' => ['port.example.com'], 'forward_port' => 0]),
        ]);

        $this->assertSame([], $report->created);
        $this->assertArrayHasKey('*.wildcard.example.com', $report->skipped);
        $this->assertArrayHasKey('inject.example.com', $report->skipped);
        $this->assertArrayHasKey('port.example.com', $report->skipped);
    }

    public function test_re_running_the_import_is_idempotent(): void
    {
        $this->writeNpmCertificate(7, ['app.example.com', 'www.app.example.com']);
        $hosts = [$this->npmHost(['certificate_id' => 7, 'ssl_forced' => 1])];

        $this->import($hosts);
        $second = $this->import($hosts);

        $this->assertSame([], $second->created);
        $this->assertSame(['app.example.com', 'www.app.example.com'], $second->alreadyExisting);
        $this->assertSame(2, ProxyHost::query()->count());
        $this->assertSame(1, SslCertificate::query()->count());
    }

    public function test_disabled_npm_hosts_are_imported_disabled(): void
    {
        $report = $this->import([$this->npmHost(['domain_names' => ['off.example.com'], 'enabled' => 0])]);

        $this->assertFalse(ProxyHost::query()->where('domain', 'off.example.com')->sole()->enabled);
        $this->assertSame(['off.example.com'], $report->createdDisabled);
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        $this->writeNpmCertificate(7, ['app.example.com', 'www.app.example.com']);

        $report = $this->import([$this->npmHost(['certificate_id' => 7, 'ssl_forced' => 1])], dryRun: true);

        $this->assertSame(['app.example.com', 'www.app.example.com'], $report->created);
        $this->assertSame(0, ProxyHost::query()->count());
        $this->assertSame(0, SslCertificate::query()->count());
    }

    public function test_command_prints_the_report_and_fails_when_something_was_skipped(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.com']);
        $json = tempnam(sys_get_temp_dir(), 'npm-hosts');
        file_put_contents($json, json_encode([
            $this->npmHost(['domain_names' => ['ok.example.com']]),
            $this->npmHost(['id' => 2, 'domain_names' => ['acl.example.com'], 'access_list_id' => 1]),
            $this->npmHost(['id' => 3, 'domain_names' => ['off.example.com'], 'enabled' => 0]),
        ]));

        $this->artisan('gateway:import-npm', ['hosts-json' => $json, '--certificates-dir' => $this->certificatesDir, '--as' => $user->email])
            ->expectsOutputToContain('+ ok.example.com')
            ->expectsOutputToContain('+ off.example.com (desabilitado, como no NPM)')
            ->expectsOutputToContain('- acl.example.com: recursos não suportados: access list')
            ->assertFailed();

        $this->assertSame(['off.example.com', 'ok.example.com'], ProxyHost::query()->orderBy('domain')->pluck('domain')->all());
    }

    public function test_command_rejects_unknown_actor_and_unreadable_input(): void
    {
        $json = tempnam(sys_get_temp_dir(), 'npm-hosts');
        file_put_contents($json, '[]');

        $this->artisan('gateway:import-npm', ['hosts-json' => $json, '--as' => 'nobody@example.com'])->assertFailed();
        $this->artisan('gateway:import-npm', ['hosts-json' => '/nao/existe.json', '--as' => 'x@example.com'])->assertFailed();
    }
}
