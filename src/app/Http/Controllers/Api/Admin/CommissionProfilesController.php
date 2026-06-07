<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CommissionProfileResource;
use App\Models\CommissionProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only listing of commission profiles for the Register Device
 * page's dropdown source.
 *
 * The MITHQAL admin app does NOT manage commission profiles — those
 * are owned by the charity application that shares this database.
 * We expose only `index` here, returning active profiles by default
 * so the dropdown can't surface retired entries.
 *
 * No policy gating beyond "must be authenticated" — the catalogue
 * is reference data, not tenant-scoped. Any admin who reaches the
 * Register Device page needs to see the options.
 */
class CommissionProfilesController extends Controller
{
    /**
     * GET /admin/api/v1/commission-profiles
     *
     * Query parameters:
     *   - search             : substring match on `name`
     *   - include_inactive=1 : surface deactivated profiles too (the
     *                          admin Settings page might want this
     *                          one day; for now it's a hatch).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CommissionProfile::query();

        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where('name', 'like', "%{$term}%");
        }

        return CommissionProfileResource::collection(
            $query->orderBy('name')->get(),
        );
    }
}
