<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts, escalation, reports, AQ desk, e-Rupantor sync, OTP codes.
 * See docs/03-data-model.md §5, §6, §8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_policies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 4)->unique();
            $table->json('params_json')->nullable();
            $table->json('channels'); // ["in_app","sms","whatsapp","email"]
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('escalation_policies', function (Blueprint $table) {
            $table->id();
            $table->string('alert_code', 4);
            $table->unsignedTinyInteger('level');
            $table->unsignedInteger('after_hours');
            $table->string('role', 40);
            $table->timestamps();
            $table->unique(['alert_code', 'level']);
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained();
            $table->string('alert_code', 4);
            $table->string('status', 14)->default('open'); // open | acknowledged | resolved | auto_closed
            $table->unsignedTinyInteger('level')->default(0);
            $table->json('details_json')->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('last_escalated_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->index(['work_id', 'alert_code', 'status']);
        });
        // One open (or acknowledged) alert per work + code.
        DB::statement("CREATE UNIQUE INDEX alerts_one_open_per_work_code ON alerts (work_id, alert_code) WHERE status IN ('open','acknowledged')");

        Schema::create('alert_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users');
            $table->string('to')->nullable();
            $table->string('template_id')->nullable();
            $table->string('status', 10)->default('queued'); // queued | sent | failed
            $table->string('provider_message_id')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name_en');
            $table->string('name_as')->nullable();
            $table->string('name_bn')->nullable();
            $table->string('type', 4); // mis | aq
            $table->json('definition_json');
            $table->json('formats');
            $table->timestamps();
        });

        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_template_id')->constrained();
            $table->json('params_json')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->string('status', 10)->default('queued');
            $table->string('format', 5);
            $table->string('file_path')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('scheduled')->default(false);
            $table->timestamps();
        });

        Schema::create('aq_requests', function (Blueprint $table) {
            $table->id();
            $table->string('question_no', 20);
            $table->string('session', 40)->nullable();
            $table->string('member_name')->nullable();
            $table->text('question_text')->nullable();
            $table->json('params_json');
            $table->foreignId('report_run_id')->nullable()->constrained();
            $table->foreignId('answered_by')->nullable()->constrained('users');
            $table->timestampTz('answered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_batches', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3); // in | out
            $table->string('mode', 4); // api | file
            $table->string('entity', 30);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->json('counts_json')->nullable();
            $table->string('file_path')->nullable();
            $table->string('status', 10)->default('running');
            $table->foreignId('started_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('sync_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_batch_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('external_id', 40)->nullable();
            $table->string('outcome', 10); // created | updated | skipped | failed
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 15)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['otp_codes', 'sync_records', 'sync_batches', 'aq_requests', 'report_runs',
            'report_templates', 'alert_notifications', 'alerts', 'escalation_policies', 'alert_policies'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
