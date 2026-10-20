<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Modules\Documents\Definitions\DocumentRegistry;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\EditRequest;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $documents, private DocumentRegistry $registry) {}

    /**
     * Archive search by number, type, date or subject. Users see their own branch's documents; auditors see all.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in($this->registry->types())],
            'serial_no' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', Rule::in(['issued', 'locked', 'edit_requested', 'superseded'])],
            'subject_type' => ['nullable', 'string', 'max:40'],
            'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $documents = $this->visible($request->user())
            ->when($filters['type'] ?? null, fn ($query, $value) => $query->where('type', $value))
            ->when($filters['serial_no'] ?? null, fn ($query, $value) => $query->where('serial_no', 'like', "%{$value}%"))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['subject_type'] ?? null, fn ($query, $value) => $query->where('subject_type', $value))
            ->when($filters['subject_id'] ?? null, fn ($query, $value) => $query->where('subject_id', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->where('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->where('created_at', '<=', $value))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ModelResource::collection($documents);
    }

    /**
     * Prints a document: the first call generates the PDF, later calls return the same stored document.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in($this->registry->types())],
            'subject_id' => ['required', 'integer'],
        ]);

        [$document, $created] = $this->documents->issue($request->user(), $data['type'], $data['subject_id']);

        return (new ModelResource($document->refresh()))->response()->setStatusCode($created ? 201 : 200);
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        $document = $this->visible($request->user())->findOrFail($id);
        $path = $this->documents->open($request->user(), $document);

        return Storage::response($path, "{$document->type}-{$document->serial_no}-v{$document->version}.pdf", [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function requestEdit(Request $request, int $id): JsonResponse
    {
        Gate::authorize('document.request_edit');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $document = $this->visible($request->user())->findOrFail($id);

        return (new ModelResource($this->documents->requestEdit($request->user(), $document, $data['reason'])->refresh()))
            ->response()->setStatusCode(201);
    }

    public function editRequests(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('document.approve_edit');

        $status = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']])['status'] ?? 'pending';

        return ModelResource::collection(
            EditRequest::with('document:id,type,serial_no,version', 'requester:id,name')
                ->where('status', $status)->orderBy('id')->paginate(25),
        );
    }

    public function decideEdit(Request $request, int $id): ModelResource
    {
        Gate::authorize('document.approve_edit');

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return new ModelResource(
            $this->documents->decideEdit($request->user(), EditRequest::findOrFail($id), $data['decision'] === 'approve', $data['reason'] ?? null)->refresh(),
        );
    }

    private function visible(User $user): Builder
    {
        if ($user->hasPermission('audit.view')) {
            return Document::query();
        }

        if ($user->branch_id !== null) {
            return Document::whereIn('created_by', User::where('branch_id', $user->branch_id)->select('id'));
        }

        return Document::where('created_by', $user->id);
    }
}
