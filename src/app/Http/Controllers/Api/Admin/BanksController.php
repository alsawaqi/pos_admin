<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\BankResource;
use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only listing of banks for the Register Device page's dropdown
 * source (Sprint 1.5 follow-up — adds the missing bank binding on
 * the device row).
 *
 * The MITHQAL admin app does NOT manage banks — those are owned by
 * the charity application that shares this database. We expose only
 * `index` here, returning active banks by default so the dropdown
 * can't surface retired entries.
 *
 * No policy gating beyond "must be authenticated" — the catalogue is
 * reference data, not tenant-scoped. Any admin who reaches the
 * Register Device page needs to see the options. Mirrors the
 * pattern set by {@see CommissionProfilesController}.
 */
class BanksController extends Controller
{
    /**
     * GET /admin/api/v1/banks
     *
     * Query parameters:
     *   - search             : substring match on `name` or `short_name`
     *                          (whichever the admin types matches)
     *   - include_inactive=1 : surface deactivated banks too (the admin
     *                          Settings page might want this one day;
     *                          for now it's a hatch).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Bank::query();

        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('short_name', 'like', "%{$term}%");
            });
        }

        return BankResource::collection(
            $query->orderBy('name')->get(),
        );
    }
}
