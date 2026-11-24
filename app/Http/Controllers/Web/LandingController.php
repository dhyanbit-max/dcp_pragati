<?php

namespace App\Http\Controllers\Web;

use App\Domain\Access\Permission;
use App\Domain\PublicSite\PublicStats;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The public front page. Signed-in staff go straight to their work. */
class LandingController extends Controller
{
    public function __invoke(Request $request, PublicStats $stats): View|RedirectResponse
    {
        if ($user = $request->user()) {
            return redirect()->route($user->can(Permission::VIEW_DASHBOARD) ? 'dashboard' : 'map');
        }

        $portal = (bool) config('dcp.public.portal');

        return view('public.landing', [
            'portal' => $portal,
            'grievances' => (bool) config('dcp.public.grievances'),
            'stats' => $portal ? $stats->summary() : null,
            'map' => $stats->map($portal),
        ]);
    }
}
