<?php

namespace App\Http\Controllers;

use App\Contracts\VideoProvider;
use App\Enums\VideoStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Support\LessonAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class LessonController extends Controller
{
    public function show(Course $course, Lesson $lesson, LessonAccess $access, VideoProvider $videoProvider): View|Response
    {
        $user = Auth::guard('tenant')->user();

        $result = $access->lessonAccess($user, $lesson);

        if ($result === 'not_found') {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($result === 'redirect_login') {
            return redirect('/login');
        }

        $enrolment = $user !== null ? $access->findEnrolment($user, $course) : null;

        if ($result === 'forbidden') {
            return response()->view('lessons.forbidden', [
                'course' => $course,
                'lesson' => $lesson,
                'enrolment' => $enrolment,
            ], Response::HTTP_FORBIDDEN);
        }

        $lesson->load('attachments');
        $lesson->loadMissing('video');

        $videoPlayback = null;

        if ($lesson->video !== null && $lesson->video->status === VideoStatus::Ready) {
            $videoPlayback = [
                'driver' => $lesson->video->provider,
                'url' => $videoProvider->playbackUrl($lesson->video, $user),
                'watermarkText' => $this->videoWatermarkText($user, $access),
            ];
        }

        [$previous, $next] = $this->siblingLessons($course, $lesson);

        return view('lessons.show', [
            'course' => $course,
            'lesson' => $lesson,
            'previous' => $previous,
            'next' => $next,
            'videoPlayback' => $videoPlayback,
        ]);
    }

    private function videoWatermarkText(?User $user, LessonAccess $access): string
    {
        $today = now()->toDateString();

        if ($user === null) {
            return __('lessons.video.preview_label')." • {$today}";
        }

        if ($access->isStaffOrOwner($user)) {
            return __('lessons.video.preview_label').' – '.$user->name;
        }

        $identifier = ($user->phone !== null && $user->phone !== '')
            ? substr($user->phone, -4)
            : Str::before((string) $user->email, '@');

        return "{$user->name} • {$identifier} • {$today}";
    }

    /**
     * Previous/next lesson in the course's chapter/position order, skipping drafts —
     * paid lessons the viewer can't open yet are still included, so Next can lead them to
     * the normal access check (guest -> login redirect, non-enrolled -> 403) rather than
     * silently disappearing.
     *
     * @return array{0: ?Lesson, 1: ?Lesson}
     */
    private function siblingLessons(Course $course, Lesson $lesson): array
    {
        $orderedLessons = Lesson::orderedPublishedForCourse($course);

        $index = $orderedLessons->search(fn (Lesson $candidate) => $candidate->id === $lesson->id);

        if ($index === false) {
            return [null, null];
        }

        return [
            $orderedLessons->get($index - 1),
            $orderedLessons->get($index + 1),
        ];
    }
}
