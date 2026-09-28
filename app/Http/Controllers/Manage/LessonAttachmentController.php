<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LessonAttachmentController extends Controller
{
    public function store(Request $request, Lesson $lesson): RedirectResponse
    {
        Gate::authorize('manageContent', $lesson->course);

        $maxKb = (int) config('coaching.max_attachment_mb', 20) * 1024;

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:'.$maxKb],
        ]);

        $file = $data['file'];
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

        return redirect("/manage/courses/{$lesson->course_id}");
    }

    public function destroy(LessonAttachment $attachment): RedirectResponse
    {
        Gate::authorize('manageContent', $attachment->lesson->course);

        $courseId = $attachment->lesson->course_id;
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return redirect("/manage/courses/{$courseId}");
    }
}
