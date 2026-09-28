<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function show(): View
    {
        $user = Auth::guard('tenant')->user();

        $view = in_array($user->role, ['owner', 'staff'], true)
            ? 'dashboard.staff'
            : 'dashboard.student';

        return view($view, ['user' => $user]);
    }
}
