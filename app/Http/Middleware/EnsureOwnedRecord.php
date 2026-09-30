<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnedRecord
{
    public function handle(Request $request, Closure $next, string $parameter, string $modelClass): Response
    {
        $value = $request->route($parameter);
        $id = $value instanceof Model ? $value->getKey() : $value;

        abort_unless(is_a($modelClass, Model::class, true), 500);

        $record = $modelClass::query()
            ->whereKey($id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $request->route()->setParameter($parameter, $record);

        return $next($request);
    }
}
