<?php

use App\Enums\VideoStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('lesson_id');
            $table->string('provider', 20)->default('fake');
            $table->string('provider_video_id')->nullable();
            $table->string('status', 20)->default(VideoStatus::AwaitingUpload->value);
            $table->string('original_filename', 255);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('error_message')->nullable();
            $table->foreignId('uploaded_by')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['tenant_id', 'lesson_id']);

            $table->foreign(['tenant_id', 'lesson_id'])
                ->references(['tenant_id', 'id'])
                ->on('lessons')
                ->cascadeOnDelete();

            $table->tenantForeign('uploaded_by', 'users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_videos');
    }
};
