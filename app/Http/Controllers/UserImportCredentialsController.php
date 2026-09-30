<?php

namespace App\Http\Controllers;

use App\Support\Csv\CsvFormulaGuard;
use App\Support\Import\ImportSessionStore;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserImportCredentialsController extends Controller
{
    private const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'X-Robots-Tag' => 'noindex',
    ];

    public function show(string $token): Response
    {
        [$payload, $store] = $this->authorizedPayload($token);

        if ($store->isExpired($payload)) {
            return response()->view('users.import.sheet-unavailable')->withHeaders(self::HEADERS);
        }

        return response()->view('users.import.sheet', [
            'token' => $token,
            'rows' => $payload['rows'] ?? [],
            'tenant' => app(TenantContext::class)->get(),
        ])->withHeaders(self::HEADERS);
    }

    public function download(string $token): StreamedResponse|Response
    {
        [$payload, $store] = $this->authorizedPayload($token);

        if ($store->isExpired($payload)) {
            return response()->view('users.import.sheet-unavailable')->withHeaders(self::HEADERS);
        }

        $rows = $payload['rows'] ?? [];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['name', 'login', 'password']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    CsvFormulaGuard::escape($row['name'] ?? ''),
                    CsvFormulaGuard::escape($row['login'] ?? ''),
                    CsvFormulaGuard::escape($row['password'] ?? ''),
                ]);
            }

            fclose($out);
        }, 'student-logins.csv', array_merge(self::HEADERS, ['Content-Type' => 'text/csv; charset=UTF-8']));
    }

    public function clear(string $token): RedirectResponse
    {
        [$payload, $store] = $this->authorizedPayload($token);

        $store->clearKeepingIdentity($token, $payload);

        return redirect('/users');
    }

    /**
     * @return array{0: array, 1: ImportSessionStore}
     */
    private function authorizedPayload(string $token): array
    {
        $user = Auth::guard('tenant')->user();
        abort_unless($user->role->canManageUsers(), 403);

        $store = new ImportSessionStore(session()->driver(), 'import.sheet', 15);
        $payload = $store->get($token);

        if ($payload === null
            || ($payload['tenant_id'] ?? null) !== app(TenantContext::class)->id()
            || ($payload['created_by'] ?? null) !== $user->id) {
            abort(404);
        }

        return [$payload, $store];
    }
}
