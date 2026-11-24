<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Works, releases, planned milestones, access tables. Money is stored in paise (bigint).
 * See docs/03-data-model.md §3 and §7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->string('erupantor_id', 40)->nullable()->unique();
            $table->string('code', 40)->unique();
            $table->text('name_en');
            $table->text('name_as')->nullable();
            $table->foreignId('scheme_type_id')->constrained();
            $table->foreignId('fund_source_id')->constrained();
            $table->foreignId('financial_year_id')->constrained();
            $table->foreignId('implementing_agency_id')->constrained();
            $table->foreignId('district_id')->constrained();
            $table->foreignId('co_district_id')->nullable()->constrained();
            $table->foreignId('constituency_id')->nullable()->constrained();
            $table->string('lgd_village_code', 10)->nullable();
            $table->foreignId('stage_template_id')->constrained();
            $table->bigInteger('sanctioned_amount');
            $table->string('sanction_order_no')->nullable();
            $table->date('sanction_date')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->geometry('site', subtype: 'point', srid: 4326)->nullable();
            $table->unsignedInteger('site_radius_m')->default(200);
            $table->string('status', 20)->default('not_started');
            $table->foreignId('current_stage_item_id')->nullable()->constrained('stage_template_items');
            $table->decimal('physical_pct', 5, 2)->default(0);
            $table->bigInteger('released_total')->default(0);
            $table->bigInteger('spent_total')->default(0);
            $table->timestampTz('last_accepted_update_at')->nullable();
            $table->foreignId('assigned_field_user_id')->nullable()->constrained('users');
            $table->boolean('is_pilot')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->spatialIndex('site');
            $table->index(['district_id', 'status']);
            $table->index('co_district_id');
            $table->index('last_accepted_update_at');
        });

        Schema::create('work_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_template_item_id')->constrained();
            $table->date('planned_date')->nullable();
            $table->date('achieved_date')->nullable();
            $table->timestamps();
            $table->unique(['work_id', 'stage_template_item_id']);
        });

        Schema::create('fund_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->date('released_on');
            $table->string('order_no')->nullable();
            $table->string('source', 12)->default('manual'); // erupantor | manual
            $table->foreignId('entered_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['work_id', 'released_on']);
        });

        Schema::create('user_jurisdictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('level', 12); // state | district | co_district | agency
            $table->foreignId('district_id')->nullable()->constrained();
            $table->foreignId('co_district_id')->nullable()->constrained();
            $table->foreignId('implementing_agency_id')->nullable()->constrained();
            $table->timestamps();
        });

        Schema::create('work_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('field'); // field | reviewer
            $table->timestamps();
            $table->unique(['work_id', 'user_id', 'role']);
        });
    }

    public function down(): void
    {
        foreach (['work_assignments', 'user_jurisdictions', 'fund_releases', 'work_stages', 'works'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
