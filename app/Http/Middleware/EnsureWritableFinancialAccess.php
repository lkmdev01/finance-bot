<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWritableFinancialAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hasWritableFinancialAccess()) {
            return $next($request);
        }

        return redirect()
            ->route('billing.plans')
            ->with('status', 'Seu teste gratuito terminou. Para continuar registrando novas informações, ative um plano.');
    }
}
