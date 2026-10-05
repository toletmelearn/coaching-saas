<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\LogTailer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /admin/logs (Phase 13, feature D) — the tail of storage/logs/laravel.log with
 * a search box. No package: LogTailer reads backwards from EOF, so a huge log
 * never gets loaded into memory. `?q=` searches the last 5 MB of the file for
 * matches (an operator searching "ERROR" wants history, not just the 100 lines
 * they could already see); without a query it's the classic last-100 tail.
 *
 * Read-only: the page can never truncate, clear or download the log.
 */
class LogViewerController extends Controller
{
    public function index(Request $request, LogTailer $tailer): View
    {
        $query = trim((string) $request->query('q', ''));

        $lines = $query === ''
            ? $tailer->tail(100)
            : $tailer->search($query);

        return view('admin.logs', [
            'lines' => array_map(LogTailer::redact(...), $lines),
            'query' => $query,
            'path' => $tailer->path(),
            'exists' => $tailer->exists(),
        ]);
    }
}
