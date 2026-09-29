<?php

namespace App\Http\Controllers\Manage;

use App\Contracts\VideoProvider;
use App\Enums\VideoStatus;
use App\Exceptions\VideoProviderException;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonVideo;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class LessonVideoController extends Controller
{
    private const ALLOWED_MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/x-matroska', 'video/webm'];

    public function startUpload(
        Request $request,
        Lesson $lesson,
        VideoProvider $provider,
        TenantContext $tenantContext,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('manageContent', $lesson->course);

        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string'],
            'size_bytes' => ['required', 'integer', 'min:1'],
        ]);

        $maxBytes = (int) config('coaching.max_video_mb', 2048) * 1024 * 1024;

        if (! in_array($data['mime_type'], self::ALLOWED_MIME_TYPES, true)) {
            return $this->failWith($lesson, 'lessons.video.unsupported_file_type');
        }

        if ($data['size_bytes'] > $maxBytes) {
            return $this->failWith($lesson, 'lessons.video.file_too_large');
        }

        if ($lesson->youtube_video_id !== null) {
            return $this->failWith($lesson, 'lessons.video.lesson_has_youtube');
        }

        $tenant = $tenantContext->get();
        $existing = LessonVideo::where('lesson_id', $lesson->id)->first();
        $oldProviderVideoId = $existing?->provider_video_id;
        $oldVideoSnapshot = $existing !== null ? clone $existing : null;

        try {
            $provider->ensureLibraryProvisioned($tenant);
            $providerVideoId = $provider->createProviderVideo($tenant, $data['filename']);

            $video = DB::transaction(function () use ($lesson, $existing, $data, $providerVideoId, $provider) {
                $attrs = [
                    'provider' => $provider->driverName(),
                    'provider_video_id' => $providerVideoId,
                    'status' => VideoStatus::AwaitingUpload,
                    'original_filename' => $data['filename'],
                    'size_bytes' => $data['size_bytes'],
                    'error_message' => null,
                    'uploaded_by' => Auth::guard('tenant')->id(),
                ];

                if ($existing !== null) {
                    $existing->forceFill($attrs)->save();

                    return $existing;
                }

                $new = new LessonVideo(['lesson_id' => $lesson->id, 'original_filename' => $data['filename']]);
                $new->forceFill($attrs);
                $new->save();

                return $new;
            });

            if ($oldVideoSnapshot !== null && $oldProviderVideoId !== null && $oldProviderVideoId !== $providerVideoId) {
                $provider->deleteVideoById($tenant, $oldVideoSnapshot, $oldProviderVideoId);
            }
        } catch (Throwable $e) {
            report($e);

            $message = $e instanceof VideoProviderException ? $e->getMessage() : __('lessons.video.upload_failed');

            return $this->failWith($lesson, null, $message);
        }

        return response()->json($provider->uploadInstructions($tenant, $video));
    }

    public function refreshStatus(Lesson $lesson, VideoProvider $provider): RedirectResponse
    {
        Gate::authorize('manageContent', $lesson->course);

        $video = LessonVideo::where('lesson_id', $lesson->id)->firstOrFail();

        try {
            $result = $provider->fetchStatus($video);

            $video->forceFill([
                'status' => $result['status'],
                'duration_seconds' => $result['duration_seconds'] ?? $video->duration_seconds,
                'error_message' => $result['error_message'] ?? null,
            ])->save();
        } catch (Throwable $e) {
            report($e);
        }

        return redirect("/manage/lessons/{$lesson->id}/edit");
    }

    public function destroy(Lesson $lesson, VideoProvider $provider, TenantContext $tenantContext): RedirectResponse
    {
        Gate::authorize('manageContent', $lesson->course);

        $video = LessonVideo::where('lesson_id', $lesson->id)->firstOrFail();
        $tenant = $tenantContext->get();
        $providerVideoId = $video->provider_video_id;
        $snapshot = clone $video;

        DB::transaction(function () use ($video) {
            $video->delete();
        });

        if ($providerVideoId !== null) {
            $provider->deleteVideoById($tenant, $snapshot, $providerVideoId);
        }

        return redirect("/manage/lessons/{$lesson->id}/edit");
    }

    private function failWith(Lesson $lesson, ?string $langKey, ?string $rawMessage = null): RedirectResponse
    {
        return redirect("/manage/lessons/{$lesson->id}/edit")
            ->withErrors(['video' => $rawMessage ?? __($langKey)]);
    }
}
