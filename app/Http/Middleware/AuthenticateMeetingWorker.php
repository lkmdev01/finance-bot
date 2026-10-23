<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMeetingWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('vexa.worker_secret');
        $timestamp = (string) $request->header('X-Worker-Timestamp', '');
        $nonce = (string) $request->header('X-Worker-Nonce', '');
        $signature = (string) $request->header('X-Worker-Signature', '');
        if ($secret === '') {
            return response()->json(['status' => 'unavailable'], 503);
        }

        $tolerance = max(30, (int) config('vexa.worker_signature_tolerance_seconds', 300));
        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > $tolerance
            || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $bodyHash = hash('sha256', $request->getContent());
        $message = implode("\n", [$request->method(), $request->getPathInfo(), $timestamp, $nonce, $bodyHash]);
        $expected = hash_hmac('sha256', $message, $secret);
        if (! preg_match('/^[a-f0-9]{64}$/i', $signature) || ! hash_equals($expected, strtolower($signature))) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        if (! Cache::add('meeting-worker-nonce:'.hash('sha256', $nonce), true, now()->addSeconds($tolerance * 2))) {
            return response()->json(['status' => 'replay_rejected'], 409);
        }

        return $next($request);
    }
}
