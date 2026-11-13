<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->requiresMobileEmailVerification()) {
            return response()->json([
                'code' => 'EMAIL_VERIFICATION_REQUIRED',
                'message' => 'Verify your email using Forgot password to continue.',
            ], 403);
        }

        return $next($request);
    }
}
