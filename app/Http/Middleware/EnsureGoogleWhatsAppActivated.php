<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGoogleWhatsAppActivated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->auth_provider === 'google' && ! $user->whatsapp_verified_at) {
            return redirect()->route('whatsapp.activation.show');
        }

        return $next($request);
    }
}
