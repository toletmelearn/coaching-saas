<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DemoRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\DemoRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DemoRequestController extends Controller
{
    public function index(): View
    {
        $demoRequests = DemoRequest::query()->orderByDesc('created_at')->paginate(20);

        return view('admin.demo-requests.index', ['demoRequests' => $demoRequests]);
    }

    public function markContacted(DemoRequest $demoRequest): RedirectResponse
    {
        $demoRequest->forceFill([
            'status' => DemoRequestStatus::Contacted,
            'contacted_at' => now(),
        ])->save();

        return redirect(url('/admin/demo-requests'));
    }
}
