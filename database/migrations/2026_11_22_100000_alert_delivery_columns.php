<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WhatsApp opt-in per user (docs/08 §3) and scheduled delivery for quiet hours. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('whatsapp_opt_in')->default(false)->after('preferred_locale');
        });
        Schema::table('alert_notifications', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->default(0)->after('alert_id');
            $table->text('body')->nullable()->after('template_id');
            $table->timestampTz('scheduled_for')->nullable()->after('status');
            $table->index(['alert_id', 'recipient_user_id', 'channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('alert_notifications', function (Blueprint $table) {
            $table->dropIndex(['alert_id', 'recipient_user_id', 'channel', 'created_at']);
            $table->dropColumn(['level', 'body', 'scheduled_for']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('whatsapp_opt_in'));
    }
};
