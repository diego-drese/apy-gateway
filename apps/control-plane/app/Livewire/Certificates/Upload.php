<?php

declare(strict_types=1);

namespace App\Livewire\Certificates;

use App\Actions\Certificate\UploadCertificateAction;
use App\Models\SslCertificate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Upload extends Component
{
    public string $certificate = '';
    public string $private_key = '';

    public function mount(): void
    {
        $this->authorize('create', SslCertificate::class);
    }

    public function save(UploadCertificateAction $action): void
    {
        $this->validate([
            'certificate' => ['required', 'string', 'max:32768'],
            'private_key' => ['required', 'string', 'max:32768'],
        ]);

        $action->handle($this->certificate, $this->private_key, auth()->user());

        $this->redirectRoute('certificates.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.certificates.upload');
    }
}
