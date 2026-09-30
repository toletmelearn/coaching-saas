<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class HelpController extends Controller
{
    public function show(): View
    {
        abort_unless(Auth::guard('tenant')->user()->role->canManageUsers(), 403);

        return view('manage.help.index');
    }
}
