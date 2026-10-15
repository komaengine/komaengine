<?php

namespace App\Http\Controllers;

use App\Support\MatureContentAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MatureContentController extends Controller
{
    public function show(Request $request, MatureContentAccess $access): View|RedirectResponse
    {
        $returnTo = $request->query('return_to');

        $access->rememberIntended($request, is_string($returnTo) ? $returnTo : null);

        if ($access->allows($request)) {
            return redirect($access->pullIntended($request, route('home')));
        }

        return view('theme::mature-gate');
    }

    public function confirm(Request $request, MatureContentAccess $access): RedirectResponse
    {
        $access->confirm($request);

        return redirect($access->pullIntended($request, route('home')));
    }

    public function decline(Request $request, MatureContentAccess $access): RedirectResponse
    {
        $access->decline($request);

        return redirect()->route('home');
    }
}
