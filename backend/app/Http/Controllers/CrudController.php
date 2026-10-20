<?php

namespace App\Http\Controllers;

use App\Http\Resources\ModelResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Shared list/show/create/update/deactivate behaviour for master-data endpoints.
 * Subclasses declare the model, validation rules and the permissions guarding reads and writes.
 */
abstract class CrudController extends Controller
{
    /** Columns matched by the ?q= search parameter. */
    protected array $searchable = ['name'];

    /** Permission needed to read; null means any signed-in user. */
    protected ?string $viewPermission = null;

    protected string $managePermission;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function rules(Request $request, ?Model $model): array;

    /**
     * Restrict the query to what the signed-in user may see.
     */
    protected function scopedQuery(Request $request): Builder
    {
        return $this->modelClass()::query();
    }

    /**
     * Last chance to adjust validated data before it is saved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepare(Request $request, array $data, ?Model $model): array
    {
        return $data;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView();

        $query = $this->scopedQuery($request);

        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $query->where(function (Builder $builder) use ($term): void {
                foreach ($this->searchable as $column) {
                    $builder->orWhere($column, 'like', '%'.$term.'%');
                }
            });
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return ModelResource::collection($query->orderByDesc('id')->paginate($perPage));
    }

    public function show(Request $request, int|string $id): ModelResource
    {
        $this->authorizeView();

        return new ModelResource($this->scopedQuery($request)->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize($this->managePermission);

        $data = $this->prepare($request, $request->validate($this->rules($request, null)), null);

        if (Schema::hasColumn($this->newModel()->getTable(), 'created_by')) {
            $data['created_by'] = $request->user()->id;
        }

        $model = $this->modelClass()::create($data);

        return (new ModelResource($model->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, int|string $id): ModelResource
    {
        Gate::authorize($this->managePermission);

        $model = $this->scopedQuery($request)->findOrFail($id);
        $model->update($this->prepare($request, $request->validate($this->rules($request, $model)), $model));

        return new ModelResource($model->refresh());
    }

    /**
     * Records are never hard-deleted when they can be referenced elsewhere: they are deactivated.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        Gate::authorize($this->managePermission);

        $model = $this->scopedQuery($request)->findOrFail($id);

        if (Schema::hasColumn($model->getTable(), 'active')) {
            $model->update(['active' => false]);

            return response()->json(['message' => 'تم الإيقاف']);
        }

        try {
            $model->delete();
        } catch (QueryException) {
            return response()->json(['message' => 'السجل مستخدم في بيانات تانية ومينفعش يتمسح'], 409);
        }

        return response()->json(['message' => 'تم الحذف']);
    }

    private function newModel(): Model
    {
        $class = $this->modelClass();

        return new $class;
    }

    private function authorizeView(): void
    {
        if ($this->viewPermission !== null) {
            Gate::authorize($this->viewPermission);
        }
    }
}
