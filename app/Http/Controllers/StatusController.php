<?php

namespace App\Http\Controllers;

use App\Services\LoadMonitor;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class StatusController extends Controller
{
    private const COPY = [
        'ok' => ['Running normally', 'Renders start right away.'],
        'busy' => ['Busy', 'Renders are queued behind other work and take longer than usual.'],
        'overloaded' => ['Overloaded', 'The render queue is long. Expect long waits; nothing is lost.'],
    ];

    public function show(LoadMonitor $monitor): View
    {
        $s = $monitor->current();

        return view('public.status', [
            'level' => $s['level'],
            'headline' => self::COPY[$s['level']][0],
            'detail' => self::COPY[$s['level']][1],
            'queued' => $s['queued'],
            'running' => $s['running'],
            'sampledAt' => Carbon::parse($s['sampled_at']),
        ]);
    }
}
