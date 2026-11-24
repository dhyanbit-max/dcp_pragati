<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** State outline for the map highlight (docs/10). One row per state; the app uses config('dcp.map.state_lgd_code'). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('lgd_code', 10)->unique();
            $table->string('name_en');
            $table->string('name_as')->nullable();
            $table->string('name_bn')->nullable();
            $table->geometry('boundary', subtype: 'multipolygon', srid: 4326)->nullable();
            $table->string('boundary_source')->nullable(); // where the outline came from (licence / authority)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
