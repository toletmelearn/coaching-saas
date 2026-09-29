<?php

namespace App\Contracts;

use App\Enums\VideoStatus;
use App\Models\LessonVideo;
use App\Models\Tenant;
use App\Models\User;

interface VideoProvider
{
    public function driverName(): string;

    /**
     * No-op for the fake driver. For bunny: creates the tenant's Stream library on its
     * first video upload if it doesn't have one yet (race-safe — see
     * BunnyVideoProvider::ensureLibraryProvisioned).
     */
    public function ensureLibraryProvisioned(Tenant $tenant): void;

    /**
     * Create the video object at the provider and return its provider-side video id
     * (a GUID for bunny, a locally-generated UUID for fake).
     */
    public function createProviderVideo(Tenant $tenant, string $title): string;

    /**
     * Data the browser needs to actually perform the upload: a local chunk-upload URL
     * for fake, or the TUS endpoint + signed headers for bunny. Never includes any raw
     * API key or token key.
     *
     * @return array<string, mixed>
     */
    public function uploadInstructions(Tenant $tenant, LessonVideo $video): array;

    /**
     * @return array{status: VideoStatus, duration_seconds?: ?int, error_message?: ?string}
     */
    public function fetchStatus(LessonVideo $video): array;

    public function deleteVideoById(Tenant $tenant, LessonVideo $capturedVideo, string $providerVideoId): void;

    /**
     * fake: a signed, expiring temporarySignedRoute URL. bunny: a signed embed URL built
     * with that tenant's own token security key. $viewer is null for a guest viewing a
     * free-preview lesson.
     */
    public function playbackUrl(LessonVideo $video, ?User $viewer): string;
}
