<?php

namespace App\Http\Controllers;

use App\Enums\VideoStatus;
use App\Models\LessonVideo;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Where the browser actually PUTs the video bytes for the `fake` driver (start-upload
 * only registers the video's metadata and hands back this signed URL). Never reachable
 * in production — the fake driver itself refuses to generate this URL there, and this
 * is checked again here as defence-in-depth. Server re-validates type/size regardless
 * of what start-upload already checked (the client could lie the second time), and the
 * stored path is keyed by the video's own database id — never the client-supplied
 * filename — so a crafted filename can never influence where the file lands on disk.
 */
class LessonVideoUploadController extends Controller
{
    private const ALLOWED_MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/x-matroska', 'video/webm'];

    public function store(Request $request, LessonVideo $lessonVideo): Response
    {
        if (app()->environment('production')) {
            abort(Response::HTTP_NOT_FOUND);
        }

        Gate::authorize('manageContent', $lessonVideo->lesson->course);

        $maxBytes = (int) config('coaching.max_video_mb', 2048) * 1024 * 1024;
        $file = $request->file('file');

        if ($file === null) {
            // When an upload exceeds PHP's own post_max_size, PHP silently discards the
            // body before populating $_FILES/$_POST — the request arrives here looking
            // like no file was sent at all, but Content-Length (which PHP fills in
            // regardless) still reports the real size the browser tried to send. Report
            // that specific, teacher-actionable case distinctly from "no file at all".
            $contentLength = (int) $request->server('CONTENT_LENGTH', 0);

            if ($contentLength > $maxBytes) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('lessons.video.file_too_large'));
            }

            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('lessons.video.unsupported_file_type'));
        }

        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('lessons.video.unsupported_file_type'));
        }

        if ($file->getSize() > $maxBytes) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('lessons.video.file_too_large'));
        }

        Storage::disk('local')->put($lessonVideo->storage_path, file_get_contents($file->getRealPath()));

        $lessonVideo->forceFill([
            'status' => VideoStatus::Ready,
            'size_bytes' => $file->getSize(),
        ])->save();

        return response()->noContent();
    }
}
