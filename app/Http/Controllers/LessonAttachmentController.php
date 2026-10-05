<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Support\Access\ContentAccessGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class LessonAttachmentController extends Controller
{
    public function show(Course $course, Lesson $lesson, LessonAttachment $attachment, ContentAccessGate $gate): Response
    {
        $user = Auth::guard('tenant')->user();

        $result = $gate->lessonState($user, $lesson);

        if ($result === 'not_found') {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($result === 'redirect_login') {
            return redirect('/login');
        }

        // forbidden, payment_needed and consent_withdrawn all refuse the file.
        if ($result !== 'ok') {
            abort(Response::HTTP_FORBIDDEN);
        }

        $contents = Storage::disk($attachment->disk)->get($attachment->path);

        // RFC 6266: filename= must be a safe ASCII fallback (quotes/backslashes escaped by
        // makeDisposition itself); the real name — quotes, non-ASCII, whatever the teacher
        // typed — travels in filename*=UTF-8''... instead, which is what browsers actually
        // display. Never hand-build this header by interpolating the name into a string.
        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $attachment->original_name) ?? 'attachment.pdf';

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $attachment->original_name,
            $asciiFallback,
        );

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
