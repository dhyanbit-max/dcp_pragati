<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress updates, photos, rule definitions and results.
 * See docs/03-data-model.md §4–5 and docs/05-rules-engine.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progress_updates', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('work_id')->constrained();
            $table->foreignId('submitted_by')->constrained('users');
            $table->string('channel', 8); // app | web | bulk | import
            $table->foreignId('stage_item_id')->constrained('stage_template_items');
            $table->decimal('physical_pct', 5, 2);
            $table->bigInteger('spent_cumulative');
            $table->text('remarks')->nullable();
            $table->timestampTz('captured_at');
            $table->timestampTz('received_at');
            $table->decimal('device_lat', 10, 7)->nullable();
            $table->decimal('device_lng', 10, 7)->nullable();
            $table->decimal('device_accuracy_m', 8, 2)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->unsignedInteger('form_seconds')->nullable(); // entry effort metric (M5)
            $table->string('status', 20); // rejected | pending_approval | accepted | returned
            $table->boolean('is_flagged')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->string('erupantor_sync_status', 12)->default('not_queued');
            $table->timestamps();

            $table->index(['work_id', 'status']);
            $table->index(['status', 'is_flagged']);
        });

        Schema::create('progress_photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('work_id')->constrained();
            $table->foreignId('progress_update_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('path');
            $table->char('sha256', 64)->index();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->boolean('is_mocked')->default(false);
            $table->timestampTz('taken_at')->nullable();
            $table->decimal('distance_from_site_m', 10, 1)->nullable();
            $table->json('exif_json')->nullable();
            $table->timestamps();
        });

        Schema::create('rule_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 4);
            $table->string('severity', 6); // block | flag | off
            $table->json('params_json')->nullable();
            $table->foreignId('scope_district_id')->nullable()->constrained('districts');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        // One definition per rule code per scope (NULL scope = state-wide default).
        DB::statement('CREATE UNIQUE INDEX rule_definitions_code_scope ON rule_definitions (code, scope_district_id) NULLS NOT DISTINCT');

        Schema::create('rule_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('progress_update_id')->constrained()->cascadeOnDelete();
            $table->string('rule_code', 4);
            $table->string('outcome', 6); // pass | flag | block
            $table->string('message_key')->nullable();
            $table->json('details_json')->nullable();
            $table->timestamps();
            $table->index(['rule_code', 'outcome']);
        });
    }

    public function down(): void
    {
        foreach (['rule_results', 'rule_definitions', 'progress_photos', 'progress_updates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
