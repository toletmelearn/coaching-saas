<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PlatformAdminDashboardController extends Controller
{
    public function show(): View
    {
        return view('admin.dashboard');
    }
}
