<?php

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Definitions\DocumentDefinition;
use App\Modules\Documents\Definitions\DocumentRegistry;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\EditRequest;
use App\Modules\Documents\Pdf\PdfRenderer;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Issues each document once, archives the PDF, locks it when it is printed, and versions it on approved edits.
 */
final class DocumentService
{
    public function __construct(private DocumentRegistry $registry, private PdfRenderer $pdf) {}

    /**
     * Returns the current document for the subject, generating it the first time.
     *
     * @return array{0: Document, 1: bool} the document and whether it was just created
     */
    public function issue(User $user, string $type, int $subjectId): array
    {
        $definition = $this->registry->get($type);
        $subject = $definition->resolve($subjectId, $user);

        return DB::transaction(function () use ($user, $definition, $subject): array {
            // Locking the subject makes concurrent "print" clicks produce a single document.
            $subject = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();

            $existing = $this->current($definition, $subject->getKey());
            if ($existing !== null) {
                return [$existing, false];
            }

            return [$this->generate($definition, $subject, $definition->serial($subject), 1, $user), true];
        });
    }

    /**
     * Path of the stored PDF. The first download counts as printing and locks the document.
     */
    public function open(User $user, Document $document): string
    {
        $this->registry->get($document->type)->resolve($document->subject_id, $user);

        if ($document->status === 'issued') {
            $document->update(['status' => 'locked', 'locked_at' => now()]);
        }

        abort_unless(Storage::exists($document->pdf_path), 404);

        return $document->pdf_path;
    }

    public function requestEdit(User $user, Document $document, string $reason): EditRequest
    {
        return DB::transaction(function () use ($user, $document, $reason): EditRequest {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->registry->get($locked->type)->resolve($locked->subject_id, $user);

            if ($locked->status !== 'locked') {
                throw ValidationException::withMessages([
                    'document' => $locked->status === 'edit_requested' ? 'فيه طلب تعديل مفتوح على المستند ده' : 'المستند لسه مش مقفول، اطبعه الأول',
                ]);
            }

            $locked->update(['status' => 'edit_requested']);

            return EditRequest::create(['document_id' => $locked->id, 'reason' => $reason, 'requested_by' => $user->id]);
        });
    }

    /**
     * Approving regenerates the document from current data as a new version; the old file stays archived (AU-02).
     */
    public function decideEdit(User $user, EditRequest $request, bool $approve, ?string $reason): EditRequest
    {
        return DB::transaction(function () use ($user, $request, $approve, $reason): EditRequest {
            $lockedRequest = EditRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($lockedRequest->status !== 'pending') {
                throw ValidationException::withMessages(['request' => 'الطلب ده اتقرر فيه قبل كده']);
            }

            if (! $approve && ($reason === null || trim($reason) === '')) {
                throw ValidationException::withMessages(['reason' => 'اكتب سبب الرفض']);
            }

            $document = Document::whereKey($lockedRequest->document_id)->lockForUpdate()->firstOrFail();

            if ($approve) {
                $definition = $this->registry->get($document->type);
                $document->update(['status' => 'superseded']);
                $this->generate($definition, $definition->find($document->subject_id), $document->serial_no, $document->version + 1, $user);
            } else {
                $document->update(['status' => 'locked']);
            }

            $lockedRequest->update([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $user->id,
                'decided_at' => now(),
            ]);

            return $lockedRequest;
        });
    }

    private function current(DocumentDefinition $definition, int|string $subjectId): ?Document
    {
        return Document::where(['type' => $definition->type(), 'subject_type' => $definition->subjectType(), 'subject_id' => $subjectId])
            ->where('status', '<>', 'superseded')
            ->orderByDesc('version')
            ->first();
    }

    private function generate(DocumentDefinition $definition, Model $subject, string $serial, int $version, User $user): Document
    {
        $html = view($definition->view(), [
            ...$definition->data($subject),
            'company' => config('gomana.company'),
            'document' => [
                'title' => $definition->title(),
                'serial' => $serial,
                'version' => $version,
                'paper' => $definition->paper(),
                'generated_at' => now()->timezone('Africa/Cairo')->format('Y/m/d H:i'),
            ],
        ])->render();

        $path = "documents/{$definition->type()}/{$serial}-v{$version}.pdf";
        Storage::put($path, $this->pdf->render($html, $definition->paper()));

        try {
            return Document::create([
                'type' => $definition->type(),
                'serial_no' => $serial,
                'subject_type' => $definition->subjectType(),
                'subject_id' => $subject->getKey(),
                'version' => $version,
                'status' => 'issued',
                'pdf_path' => $path,
                'created_by' => $user->id,
            ]);
        } catch (Throwable $exception) {
            Storage::delete($path);

            throw $exception;
        }
    }
}
