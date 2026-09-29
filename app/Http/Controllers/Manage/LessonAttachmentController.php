<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LessonAttachmentController extends Controller
{
    public function store(Request $request, Lesson $lesson): RedirectResponse
    {
        Gate::authorize('manageContent', $lesson->course);

        $maxMb = (int) config('coaching.max_attachment_mb', 20);
        $maxKb = $maxMb * 1024;

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:pdf', 'max:'.$maxKb],
        ], [
            'file.required' => __('courses.manage.attachment_errors.required'),
            'file.mimes' => __('courses.manage.attachment_errors.not_pdf'),
            'file.max' => __('courses.manage.attachment_errors.too_large', ['max' => $maxMb]),
        ]);

        if ($validator->fails()) {
            // Explicit redirect target (not the implicit back()) — the upload form always
            // lives on the lesson edit page, and a test client sends no Referer header for
            // back() to fall back on.
            return redirect("/manage/lessons/{$lesson->id}/edit")->withErrors($validator);
        }

        $file = $validator->validated()['file'];
        $disk = 'local';
        $path = sprintf('tenants/%d/lessons/%d/%s.pdf', $lesson->tenant_id, $lesson->id, Str::random(20));

        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        $attachment = new LessonAttachment([
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => 'application/pdf',
            'size_bytes' => $file->getSize(),
        ]);
        $attachment->lesson()->associate($lesson);
        $attachment->forceFill(['disk' => $disk, 'path' => $path]);
        $attachment->save();

        return redirect("/manage/lessons/{$lesson->id}/edit")
            ->with('attachment_uploaded', $attachment->original_name);
    }

    public function destroy(LessonAttachment $attachment): RedirectResponse
    {
        Gate::authorize('manageContent', $attachment->lesson->course);

        $lessonId = $attachment->lesson_id;
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return redirect("/manage/lessons/{$lessonId}/edit");
    }
}
