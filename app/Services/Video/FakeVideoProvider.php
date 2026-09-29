<?php

namespace App\Services\Video;

use App\Contracts\VideoProvider;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores the uploaded file on the private `local` disk and plays it back through a
 * signed, tenant-access-checked Laravel route. Never allowed in production — see
 * `config('coaching.video_driver')` / app:preflight, and the guard below as
 * defence-in-depth in case something binds this driver at runtime anyway.
 */
class FakeVideoProvider implements VideoProvider
{
    public function driverName(): string
    {
        return 'fake';
    }

    public function ensureLibraryProvisioned(Tenant $tenant): void
    {
        $this->refuseInProduction();
    }

    public function createProviderVideo(Tenant $tenant, string $title): string
    {
        $this->refuseInProduction();

        return (string) Str::uuid();
    }

    public function uploadInstructions(Tenant $tenant, LessonVideo $video): array
    {
        $this->refuseInProduction();

        return [
            'driver' => 'fake',
            'upload_url' => URL::temporarySignedRoute(
                'lesson-videos.fake-upload',
                now()->addMinutes((int) config('coaching.video_upload_signature_ttl_minutes', 60)),
                ['lessonVideo' => $video->id],
            ),
        ];
    }

    public function fetchStatus(LessonVideo $video): array
    {
        return ['status' => $video->status];
    }

    public function deleteVideoById(Tenant $tenant, LessonVideo $capturedVideo, string $providerVideoId): void
    {
        Storage::disk('local')->delete($capturedVideo->storage_path);
    }

    public function playbackUrl(LessonVideo $video, ?User $viewer): string
    {
        return URL::temporarySignedRoute(
            'lesson-videos.stream',
            now()->addMinutes((int) config('coaching.video_embed_ttl_minutes', 10)),
            ['lessonVideo' => $video->id],
        );
    }

    private function refuseInProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The fake video driver is never allowed in production.');
        }
    }
}
