<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /admin/audit (Phase 13, feature F) — the append-only trail rendered back to
 * the operator, filterable by action.
 *
 * Admins are eager-loaded for display; a row whose admin account was deleted
 * since still renders (no FK by design) as its raw admin id.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $action = trim((string) $request->query('action', ''));

        $actions = AdminAuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $logs = AdminAuditLog::query()
            ->filterByAction($action)
            ->with('admin:id,name,email')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit', [
            'logs' => $logs,
            'actions' => $actions,
            'action' => $action,
        ]);
    }
}
