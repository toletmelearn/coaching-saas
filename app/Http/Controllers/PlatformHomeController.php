<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PlatformHomeController extends Controller
{
    public function show(): View
    {
        return view('platform.home');
    }
}
