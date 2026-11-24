<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `feature:grievances` → 404 unless config('dcp.public.grievances') is on. Switched-off pages do not exist. */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless((bool) config("dcp.public.$feature"), 404);

        return $next($request);
    }
}
