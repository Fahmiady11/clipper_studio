<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\RenderQuota;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PricingController extends Controller
{
    public function show(RenderQuota $quota): View
    {
        $user = Auth::user();

        return view('pricing', [
            'plans' => Plan::orderBy('sort')->get(),
            'current' => $quota->plan($user),
            'usedMinutes' => intdiv($quota->usedSeconds($user), 60),
            'resetsOn' => now()->addMonthNoOverflow()->startOfMonth(),
        ]);
    }
}
