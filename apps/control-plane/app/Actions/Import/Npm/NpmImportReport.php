<?php

declare(strict_types=1);

namespace App\Actions\Import\Npm;

/**
 * Outcome of one import run, per domain — the operator reviews it before the cutover, so every
 * domain must end up in exactly one bucket (created, already existing, or skipped with a reason).
 */
final class NpmImportReport
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> subset of $created imported disabled (disabled in NPM too) */
    public array $createdDisabled = [];

    /** @var list<string> */
    public array $alreadyExisting = [];

    /** @var array<string, string> domain => reason */
    public array $skipped = [];

    /** @var array<string, list<string>> domain => features not reproduced */
    public array $warnings = [];

    /** @var list<int> NPM certificate ids uploaded during this run */
    public array $certificatesUploaded = [];

    public function hasSkipped(): bool
    {
        return $this->skipped !== [];
    }
}
