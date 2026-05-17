<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SpaController extends Controller
{
    /**
     * Render the SPA shell.
     *
     * The route-level guest/auth middleware already enforces access, but
     * we duplicate the check here as a safety net so an authenticated user
     * who somehow reaches the login URL is always bounced to /admin
     * regardless of cached middleware config in the container.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($request->routeIs('login') && Auth::guard('web')->check()) {
            return redirect()->intended('/admin');
        }

        return view('app');
    }
}
