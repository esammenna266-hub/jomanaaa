<?php

namespace App\Modules\Documents\Definitions;

use App\Modules\Identity\Models\User;
use App\Modules\MasterData\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * One printable document type: who may issue it, what its serial is, what data its template needs.
 */
abstract class DocumentDefinition
{
    /** Stored in documents.type. */
    abstract public function type(): string;

    /** 'A4' or 'A5'. */
    abstract public function paper(): string;

    /** Blade view under resources/views. */
    abstract public function view(): string;

    abstract public function title(): string;

    /** Table-style name stored in documents.subject_type. */
    abstract public function subjectType(): string;

    /** The subject without any permission checks (used when an approved edit regenerates the file). */
    abstract public function find(int $subjectId): Model;

    /** The subject if the user may issue and read this document, otherwise an HTTP error. */
    abstract public function resolve(int $subjectId, User $user): Model;

    /** Serial printed on the document; allocates a new one when the type owns its own sequence. */
    abstract public function serial(Model $subject): string;

    /**
     * @return array<string, mixed> variables for the template
     */
    abstract public function data(Model $subject): array;

    protected function authorizeAny(string ...$permissions): void
    {
        abort_unless(Gate::any($permissions), 403);
    }

    protected function currencyCode(int $currencyId): string
    {
        return Currency::findOrFail($currencyId)->code;
    }

    protected function currencyName(int $currencyId): string
    {
        return Currency::findOrFail($currencyId)->name_ar;
    }

    /**
     * Inline data URI so the PDF engine needs no file or network access.
     */
    protected function imageDataUri(?string $path): ?string
    {
        if ($path === null || ! Storage::exists($path)) {
            return null;
        }

        $mime = Storage::mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::get($path));
    }
}
