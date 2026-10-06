<?php

namespace App\Http\Controllers;

use App\Models\LessonVideo;
use App\Support\Access\ContentAccessGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class LessonVideoStreamController extends Controller
{
    /**
     * Signed (10-minute) URL — the `signed` route middleware already rejects a missing/
     * expired/tampered signature with a 403 before this method runs. Access is re-checked
     * here on every request (not just at page render), since it could have been revoked in
     * the meantime. BinaryFileResponse (via response()->file()) handles Range requests
     * (206 Partial Content) automatically.
     */
    public function show(LessonVideo $lessonVideo, ContentAccessGate $gate): BinaryFileResponse
    {
        $user = Auth::guard('tenant')->user();

        if ($gate->lessonState($user, $lessonVideo->lesson) !== 'ok') {
            abort(Response::HTTP_FORBIDDEN);
        }

        if (! Storage::disk('local')->exists($lessonVideo->storage_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $response = response()->file(Storage::disk('local')->path($lessonVideo->storage_path), [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // response()->file() defaults BinaryFileResponse's $public constructor arg to true
        // (adding a "public" Cache-Control directive) unless told otherwise — flip it here
        // rather than constructing BinaryFileResponse directly, since Symfony always
        // alphabetically sorts Cache-Control directives regardless of set order.
        $response->setPrivate();

        return $response;
    }
}
