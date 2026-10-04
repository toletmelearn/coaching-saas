<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Support\Admin\ArtisanRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * /admin/backups (Phase 13, feature C) — no extra package: the panel drives the
 * spatie/laravel-backup installation the deploy runbook already schedules.
 *
 * Files live on the `backups` disk (storage/app/backups, config/filesystems.php),
 * the same destination config/backup.php writes to. Filenames are validated as
 * bare `*.zip` basenames before any disk operation, so neither traversal nor a
 * second path segment can reach the resolver. Every mutating action (run,
 * delete) is audit-logged; download is read-only.
 */
class BackupsController extends Controller
{
    public function index(): View
    {
        $disk = Storage::disk('backups');

        $files = collect($disk->files())
            ->filter(fn (string $file): bool => str_ends_with($file, '.zip'))
            ->map(fn (string $file): array => [
                'name' => $file,
                'size' => $disk->size($file),
                'modified' => $disk->lastModified($file),
            ])
            ->sortByDesc('modified')
            ->values();

        return view('admin.backups', ['files' => $files]);
    }

    public function run(Request $request, ArtisanRunner $artisan): RedirectResponse
    {
        // --disable-notifications: a deployment without working mail must not
        // fail (or spam) after a successful backup.
        $result = $artisan->call('backup:run', ['--disable-notifications' => true]);

        AdminAuditLog::record(
            'backup_run',
            'backup',
            adminId: Auth::guard('platform_admin')->user()?->id,
            ipAddress: $request->ip(),
        );

        $flash = $result['code'] === 0 ? 'status' : 'error';
        $message = $result['code'] === 0
            ? __('platform.admin.backups.ran_ok')
            : __('platform.admin.backups.ran_fail');

        return redirect('/admin/backups')
            ->with($flash, $message)
            ->with('backup_output', mb_substr($result['output'], -4000));
    }

    public function download(Request $request): Response
    {
        $file = $this->resolveFile($request);

        return Storage::disk('backups')->download($file);
    }

    public function delete(Request $request): RedirectResponse
    {
        $file = $this->resolveFile($request);

        Storage::disk('backups')->delete($file);

        AdminAuditLog::record(
            'backup_delete',
            'backup:'.$file,
            adminId: Auth::guard('platform_admin')->user()?->id,
            ipAddress: $request->ip(),
        );

        return redirect('/admin/backups')
            ->with('status', __('platform.admin.backups.deleted', ['file' => $file]));
    }

    /**
     * Accept only a bare *.zip basename that actually exists on the backups disk
     * — `../.env`, absolute paths and nested segments all die here (404).
     */
    private function resolveFile(Request $request): string
    {
        $file = $request->query('file') ?? $request->input('file');

        abort_unless(is_string($file) && $file !== '', 404);

        $base = basename($file);

        abort_unless($base === $file && str_ends_with($base, '.zip'), 404);
        abort_unless(Storage::disk('backups')->exists($base), 404);

        return $base;
    }
}
