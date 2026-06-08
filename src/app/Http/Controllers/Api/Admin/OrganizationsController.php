<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only listing of organizations for the Register Device page's dropdown
 * (the beneficiary org a device's card round-up donations go to).
 *
 * Organizations are owned by the charity application that shares this database;
 * POS only reads them. Returns active organizations by default so the dropdown
 * can't surface deactivated ones. Mirrors {@see BanksController} /
 * {@see CommissionProfilesController} — reference data, not tenant-scoped.
 */
class OrganizationsController extends Controller
{
    /**
     * GET /admin/api/v1/organizations
     *
     * Query parameters:
     *   - search             : substring match on `name`
     *   - include_inactive=1 : surface deactivated organizations too
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Organization::query();

        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where('name', 'like', "%{$term}%");
        }

        return OrganizationResource::collection(
            $query->orderBy('name')->get(),
        );
    }
}
