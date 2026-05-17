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
     * Every middleware layer that gates these routes can in theory be
     * defeated by stale config cache or a misconfigured alias. We therefore
     * duplicate the guard check here so the controller body itself NEVER
     * serves the shell when the auth state does not match the route.
     *
     *   /login          authed user   -> 302 /admin
     *   /admin/{path?}  guest visitor -> 302 /login (with intended)
     *
     * Match on route names (not path strings) so any future route name
     * change is caught by the named-route lookup.
     */
    public function __invoke(Request $request): View|RedirectResponse
    {
        $authed = Auth::guard('web')->check();

        if ($request->routeIs('login') && $authed) {
            return redirect()->intended('/admin');
        }

        if ($request->routeIs('admin.dashboard') && ! $authed) {
            return redirect()->guest(route('login'));
        }

        return view('app');
    }
}
