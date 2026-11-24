<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masters: administrative units, fund sources, scheme types, agencies, FYs, stage templates.
 * See docs/03-data-model.md §2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->string('lgd_code', 10)->unique();
            $table->string('erupantor_code', 20)->nullable()->unique();
            $this->names($table);
            $table->geometry('boundary', subtype: 'multipolygon', srid: 4326)->nullable();
            $table->boolean('is_pilot')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('co_districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained();
            $table->string('lgd_code', 10)->nullable()->unique();
            $table->string('erupantor_code', 20)->nullable()->unique();
            $this->names($table);
            $table->geometry('boundary', subtype: 'multipolygon', srid: 4326)->nullable();
            $table->boolean('is_pilot')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('constituencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained();
            $table->unsignedSmallInteger('ac_no')->unique();
            $this->names($table);
            $table->string('parliamentary_constituency')->nullable();
            $table->geometry('boundary', subtype: 'multipolygon', srid: 4326)->nullable();
            $table->timestamps();
        });

        foreach (['fund_sources', 'scheme_types', 'implementing_agencies'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique();
                $this->names($table);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::create('financial_years', function (Blueprint $table) {
            $table->id();
            $table->string('code', 7)->unique(); // 2026-27
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });

        Schema::create('stage_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $this->names($table);
            $table->timestamps();
        });

        Schema::create('stage_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $this->names($table);
            $table->decimal('min_pct', 5, 2);
            $table->decimal('max_pct', 5, 2);
            $table->unsignedSmallInteger('typical_days')->nullable();
            $table->timestamps();
            $table->unique(['stage_template_id', 'seq']);
        });

        Schema::create('code_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 40);
            $table->string('local_code', 40);
            $table->string('erupantor_code', 40);
            $table->timestamps();
            $table->unique(['entity', 'local_code']);
            $table->unique(['entity', 'erupantor_code']);
        });
    }

    private function names(Blueprint $table): void
    {
        $table->string('name_en');
        $table->string('name_as')->nullable();
        $table->string('name_bn')->nullable();
    }

    public function down(): void
    {
        foreach (['code_mappings', 'stage_template_items', 'stage_templates', 'financial_years',
            'implementing_agencies', 'scheme_types', 'fund_sources', 'constituencies',
            'co_districts', 'districts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
