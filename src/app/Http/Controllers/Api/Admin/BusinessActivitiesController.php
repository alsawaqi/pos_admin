<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\BusinessActivityResource;
use App\Models\BusinessActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BusinessActivitiesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BusinessActivity::query()->active();

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->value());
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($q) use ($term): void {
                $q->where('name_en', 'like', "%{$term}%")
                    ->orWhere('name_ar', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%");
            });
        }

        return BusinessActivityResource::collection(
            $query->orderBy('display_order')->orderBy('name_en')->get()
        );
    }
}
