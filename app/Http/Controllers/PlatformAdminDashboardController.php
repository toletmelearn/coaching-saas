<?php

namespace App\Http\Controllers;

use App\Support\PlatformStats;
use Illuminate\View\View;

class PlatformAdminDashboardController extends Controller
{
    public function show(PlatformStats $stats): View
    {
        return view('admin.dashboard', ['counts' => $stats->dashboardCounts()]);
    }
}
