<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Citizen grievances about works (docs/20) and their timeline. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grievances', function (Blueprint $table) {
            $table->id();
            $table->string('ref_no', 20)->unique();
            $table->foreignId('district_id')->constrained();
            $table->foreignId('co_district_id')->nullable()->constrained();
            $table->foreignId('work_id')->nullable()->constrained(); // matched by the handling officer
            $table->string('work_reference', 120)->nullable();       // what the citizen typed (code or name)
            $table->string('category', 30);
            $table->string('subject', 150);
            $table->text('description');
            $table->string('location_text', 255)->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->string('photo_path')->nullable();
            // Citizen contact: the only personal data held. Cleared after retention_days (grievances:purge).
            $table->string('citizen_name', 120)->nullable();
            $table->string('citizen_mobile', 15)->nullable();
            $table->string('citizen_locale', 5)->default('en');
            $table->timestampTz('mobile_verified_at')->nullable();
            $table->timestampTz('consented_at');
            $table->string('status', 20)->default('submitted');
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->timestampTz('acknowledge_by');
            $table->timestampTz('resolve_by');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestampTz('personal_data_purged_at')->nullable();
            $table->timestamps();

            $table->index(['district_id', 'status']);
            $table->index('co_district_id');
            $table->index('citizen_mobile');
        });

        Schema::create('grievance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grievance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained(); // null: the citizen or the system
            $table->string('type', 20);                               // submitted, status, note, assigned, work_linked
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_public')->default(false);            // shown to the citizen when tracking
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grievance_events');
        Schema::dropIfExists('grievances');
    }
};
