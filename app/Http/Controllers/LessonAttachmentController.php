<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Support\LessonAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class LessonAttachmentController extends Controller
{
    public function show(Course $course, Lesson $lesson, LessonAttachment $attachment, LessonAccess $access): Response
    {
        $user = Auth::guard('tenant')->user();

        $result = $access->lessonAccess($user, $lesson);

        if ($result === 'not_found') {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($result === 'redirect_login') {
            return redirect('/login');
        }

        if ($result === 'forbidden') {
            abort(Response::HTTP_FORBIDDEN);
        }

        $contents = Storage::disk($attachment->disk)->get($attachment->path);

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$attachment->original_name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
