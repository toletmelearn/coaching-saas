<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Deliberately does NOT use WithoutModelEvents. This app relies on model events for
    // data invariants that seeding must never bypass: Course's slug auto-generation,
    // Chapter/Lesson/LessonAttachment position auto-fill, BelongsToTenant's tenant_id
    // auto-fill, and Lesson's chapter/course-match guard all run in `creating`/`saving`
    // hooks. WithoutModelEvents wraps the whole run in Model::withoutEvents(), silently
    // skipping every one of them — see CLAUDE.md.

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(TenantSeeder::class);
    }
}
