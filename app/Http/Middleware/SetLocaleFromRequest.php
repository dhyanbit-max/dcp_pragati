<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Picks en / as / bn from Accept-Language, then the user's preference, then the default. */
class SetLocaleFromRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('dcp.locales');
        $locale = $request->getPreferredLanguage($supported);

        if (! $request->headers->has('Accept-Language') && $request->user()) {
            $locale = $request->user()->preferred_locale;
        }
        if ($request->hasSession() && in_array($request->session()->get('locale'), $supported, true)) {
            $locale = $request->session()->get('locale');
        }

        app()->setLocale(in_array($locale, $supported, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
