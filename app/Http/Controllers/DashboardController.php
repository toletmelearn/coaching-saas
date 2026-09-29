<?php

namespace App\Http\Controllers;

use App\Models\Enrolment;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function show(): View
    {
        $user = Auth::guard('tenant')->user();

        if ($user->role->canManageUsers()) {
            return view('dashboard.staff', ['user' => $user]);
        }

        $enrolments = Enrolment::where('user_id', $user->id)->with('course')->get();
        $active = $enrolments->filter(fn (Enrolment $enrolment) => $enrolment->isValidNow());
        $ended = $enrolments->reject(fn (Enrolment $enrolment) => $enrolment->isValidNow());

        return view('dashboard.student', ['user' => $user, 'active' => $active, 'ended' => $ended]);
    }
}
