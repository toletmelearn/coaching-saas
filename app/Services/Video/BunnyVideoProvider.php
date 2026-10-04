<?php

namespace App\Services\Video;

use App\Contracts\VideoProvider;
use App\Enums\VideoStatus;
use App\Exceptions\VideoProviderException;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Admin\ServiceSettings;
use App\Support\Video\BunnyEmbedTokenSigner;
use App\Support\Video\BunnyUploadSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * One Bunny Stream library per tenant (VIDEO.md, AGENT_RULES.md invariant #13). The
 * platform's single account-level API key (ServiceSettings::get('bunny_account_api_key'),
 * read DB-first with config('services.bunny.account_api_key') as the fallback)
 * is used only to create a tenant's library; every other call — including signing
 * embed view tokens — uses that tenant's own library API key (verified against
 * https://bunny.net/docs/stream/mobile-sdk-token-authentication: "the token security
 * key is your Video Library API Key" — there is no separate token key). See
 * docs/specs/phase-5-video.md for the verified endpoint/header details.
 */
class BunnyVideoProvider implements VideoProvider
{
    public function driverName(): string
    {
        return 'bunny';
    }

    /**
     * Race-safe: a `lockForUpdate()` row lock on the tenant, re-checked inside the
     * transaction, means two concurrent first uploads for the same tenant can never both
     * observe "no library yet" and both create one — the second to acquire the lock sees
     * the first's already-saved library id and returns without calling Bunny again.
     */
    public function ensureLibraryProvisioned(Tenant $tenant): void
    {
        if ($tenant->bunny_library_id !== null) {
            return;
        }

        DB::transaction(function () use ($tenant) {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);

            if ($locked->bunny_library_id !== null) {
                $tenant->forceFill($locked->only([
                    'bunny_library_id', 'bunny_library_api_key', 'bunny_library_created_at',
                ]));

                return;
            }

            $response = Http::withHeaders(['AccessKey' => ServiceSettings::get('bunny_account_api_key')])
                ->post('https://api.bunny.net/videolibrary', ['Name' => "tenant-{$tenant->id}"]);

            if ($response->failed()) {
                throw new VideoProviderException(__('lessons.video.upload_failed'));
            }

            $locked->forceFill([
                'bunny_library_id' => $response->json('Id'),
                'bunny_library_api_key' => $response->json('ApiKey'),
                'bunny_library_created_at' => now(),
            ])->save();

            $tenant->forceFill($locked->only([
                'bunny_library_id', 'bunny_library_api_key', 'bunny_library_created_at',
            ]));
        });
    }

    public function createProviderVideo(Tenant $tenant, string $title): string
    {
        $response = Http::withHeaders(['AccessKey' => $tenant->bunny_library_api_key])
            ->post("https://video.bunnycdn.com/library/{$tenant->bunny_library_id}/videos", ['title' => $title]);

        if ($response->failed()) {
            throw new VideoProviderException(__('lessons.video.upload_failed'));
        }

        return (string) $response->json('guid');
    }

    public function uploadInstructions(Tenant $tenant, LessonVideo $video): array
    {
        $headers = (new BunnyUploadSignature)->headersFor(
            libraryId: (int) $tenant->bunny_library_id,
            apiKey: (string) $tenant->bunny_library_api_key,
            videoId: (string) $video->provider_video_id,
        );

        return [
            'driver' => 'bunny',
            'tus_endpoint' => 'https://video.bunnycdn.com/tusupload',
            'headers' => $headers,
            'metadata' => [
                'filetype' => 'video/mp4',
                'title' => $video->original_filename,
            ],
        ];
    }

    public function fetchStatus(LessonVideo $video): array
    {
        $tenant = Tenant::findOrFail($video->tenant_id);

        $response = Http::withHeaders(['AccessKey' => $tenant->bunny_library_api_key])
            ->get("https://video.bunnycdn.com/library/{$tenant->bunny_library_id}/videos/{$video->provider_video_id}");

        if ($response->failed()) {
            return ['status' => VideoStatus::Processing];
        }

        $bunnyStatus = (int) $response->json('status');

        return match ($bunnyStatus) {
            4 => ['status' => VideoStatus::Ready, 'duration_seconds' => $response->json('length')],
            5 => ['status' => VideoStatus::Failed, 'error_message' => __('lessons.video.processing_failed')],
            default => ['status' => VideoStatus::Processing],
        };
    }

    public function deleteVideoById(Tenant $tenant, LessonVideo $capturedVideo, string $providerVideoId): void
    {
        Http::withHeaders(['AccessKey' => $tenant->bunny_library_api_key])
            ->delete("https://video.bunnycdn.com/library/{$tenant->bunny_library_id}/videos/{$providerVideoId}");
    }

    public function playbackUrl(LessonVideo $video, ?User $viewer): string
    {
        $tenant = Tenant::findOrFail($video->tenant_id);

        if ($tenant->bunny_library_api_key === null) {
            throw new VideoProviderException(__('lessons.video.playback_unavailable'));
        }

        return (new BunnyEmbedTokenSigner)->embedUrl(
            libraryId: (int) $tenant->bunny_library_id,
            videoId: (string) $video->provider_video_id,
            tokenSecurityKey: $tenant->bunny_library_api_key,
        );
    }
}
