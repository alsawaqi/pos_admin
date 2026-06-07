<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateBranchAction;
use App\Actions\Admin\DeleteBranchAction;
use App\Actions\Admin\UpdateBranchAction;
use App\Data\Admin\CreateBranchData;
use App\Data\Admin\UpdateBranchData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\UpdateBranchRequest;
use App\Http\Resources\Admin\BranchResource;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class BranchesController extends Controller
{
    public function __construct(
        private readonly CreateBranchAction $createBranch,
        private readonly UpdateBranchAction $updateBranch,
        private readonly DeleteBranchAction $deleteBranch,
    ) {}

    /**
     * DELETE /admin/api/v1/branches/{branch:uuid}
     *
     * Soft-deletes the branch. Refuses with 409 when there are
     * still active devices assigned — surfaces the action's
     * RuntimeException so the SPA can render the message inline.
     */
    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        $this->authorize('delete', $branch);

        try {
            $this->deleteBranch->handle($branch, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(null, 204);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Branch::class);

        $query = Branch::query()
            ->with('company:id,uuid,name,name_ar')
            ->withCount('devices');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        if ($request->filled('status')) {
            $statuses = (array) $request->input('status');
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('name_ar', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('manager_name', 'like', "%{$term}%");
            });
        }

        $branches = $query
            ->orderBy('company_id')
            ->orderBy('name')
            ->paginate(min($request->integer('per_page', 25), 100));

        return BranchResource::collection($branches);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $this->authorize('create', Branch::class);

        $data = CreateBranchData::from($request->validated());
        $branch = $this->createBranch->handle($data, $request->user());

        return BranchResource::make($branch->load('company'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        $this->authorize('view', $branch);

        return BranchResource::make($branch->load('company')->loadCount('devices'));
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        $this->authorize('update', $branch);

        $data = UpdateBranchData::from($request->validated());
        $branch = $this->updateBranch->handle($branch, $data, $request->user());

        return BranchResource::make($branch->load('company'));
    }
}
