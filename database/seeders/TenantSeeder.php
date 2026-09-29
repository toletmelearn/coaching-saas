<?php

namespace Database\Seeders;

use App\Enums\DomainType;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['name' => 'Demo Institute'],
            ['status' => TenantStatus::Active],
        );

        TenantDomain::query()->firstOrCreate(
            ['domain' => 'demo.coaching.test'],
            [
                'tenant_id' => $tenant->id,
                'type' => DomainType::Subdomain,
                'is_primary' => true,
                'verified_at' => now(),
            ],
        );

        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'Skipping demo user seeding: local-only credentials are never seeded outside local/testing environments.'
            );

            return;
        }

        // Local dev/demo credentials — see README "Local credentials". Never seeded
        // outside local/testing (guarded above).
        app(TenantContext::class)->runAs($tenant, function () {
            $owner = User::query()->firstOrCreate(
                ['email' => 'owner@demo.coaching.test'],
                ['name' => 'Demo Owner', 'password' => Hash::make('password')],
            );
            $owner->forceFill(['role' => UserRole::Owner, 'status' => UserStatus::Active, 'must_change_password' => false])->save();

            $student = User::query()->firstOrCreate(
                ['email' => 'student@demo.coaching.test'],
                ['name' => 'Demo Student', 'password' => Hash::make('password')],
            );
            $student->forceFill(['role' => UserRole::Student, 'status' => UserStatus::Active, 'must_change_password' => false])->save();

            $this->seedDemoCourse($owner, $student);

            Course::query()->firstOrCreate(
                ['title' => 'Chemistry – Class 11'],
                ['created_by' => $owner->id],
            );
        });
    }

    private function seedDemoCourse(User $owner, User $student): void
    {
        $course = Course::query()->firstOrCreate(
            ['title' => 'Physics – Class 11'],
            ['created_by' => $owner->id],
        );
        $course->forceFill(['status' => 'published', 'published_at' => now()])->save();

        if ($course->chapters()->exists()) {
            return;
        }

        $chapter1 = $course->chapters()->create(['title' => 'Mechanics']);
        $chapter2 = $course->chapters()->create(['title' => 'Optics']);

        $free = $chapter1->lessons()->create([
            'course_id' => $course->id,
            'title' => 'Introduction to Mechanics',
            'is_free_preview' => true,
            // Neutral placeholder — never a real video id. See README "Local credentials".
            'youtube_video_id' => 'xxxxxxxxxxx',
        ]);
        $free->forceFill(['status' => 'published', 'published_at' => now()])->save();

        $draft = $chapter1->lessons()->create([
            'course_id' => $course->id,
            'title' => 'Newton\'s Laws (coming soon)',
        ]);
        // Draft by default — nothing further to set.

        $paid1 = $chapter1->lessons()->create([
            'course_id' => $course->id,
            'title' => 'Work, Energy and Power',
        ]);
        $paid1->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $this->attachSamplePdf($paid1);

        $paid2 = $chapter2->lessons()->create([
            'course_id' => $course->id,
            'title' => 'Reflection and Refraction',
        ]);
        $paid2->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $this->attachSamplePdf($paid2);

        $enrolment = new Enrolment([
            'course_id' => $course->id,
            'user_id' => $student->id,
            'starts_at' => now()->subWeek(),
        ]);
        $enrolment->forceFill(['enrolled_by' => $owner->id]);
        $enrolment->save();
    }

    private function attachSamplePdf(Lesson $lesson): void
    {
        $disk = 'local';
        $path = sprintf('tenants/%d/lessons/%d/sample-notes.pdf', $lesson->tenant_id, $lesson->id);

        Storage::disk($disk)->put($path, '%PDF-1.4 sample demo notes');

        $attachment = new LessonAttachment([
            'original_name' => 'sample-notes.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => Storage::disk($disk)->size($path),
        ]);
        $attachment->lesson()->associate($lesson);
        $attachment->forceFill(['disk' => $disk, 'path' => $path]);
        $attachment->save();
    }
}
