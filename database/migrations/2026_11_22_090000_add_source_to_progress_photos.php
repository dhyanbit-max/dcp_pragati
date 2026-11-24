<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a photo came from: 'app' (in-app camera, trusted GPS/time) or 'web' (file upload, always flagged by R12). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_photos', function (Blueprint $table) {
            $table->string('source', 4)->default('app')->after('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('progress_photos', fn (Blueprint $table) => $table->dropColumn('source'));
    }
};
