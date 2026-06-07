<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CsrfTokenController extends Controller
{
    /**
     * Hand the SPA a guaranteed-fresh CSRF token.
     *
     * The token returned matches the session that owns the cookie the
     * browser will send on the next request. Calling this just before
     * any state-changing POST (especially the very first login submit
     * after a session rotation) eliminates the class of 419 mismatch
     * failures where the meta tag was rendered against a now-stale
     * session.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Touching the token here causes Laravel to issue/persist it on
        // the active session. The web middleware group's StartSession +
        // VerifyCsrfToken will send the matching XSRF-TOKEN cookie.
        $token = (string) $request->session()->token();

        return response()->json([
            'csrf_token' => $token,
        ]);
    }
}
