<?php

declare(strict_types=1);

namespace App\Livewire\Certificates;

use App\Models\SslCertificate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public function render()
    {
        $this->authorize('viewAny', SslCertificate::class);

        return view('livewire.certificates.index', [
            'certificates' => SslCertificate::query()->latest()->paginate(15),
        ]);
    }
}
