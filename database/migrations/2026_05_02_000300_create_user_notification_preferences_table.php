<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::create('user_notification_preferences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('event');
            $table->string('channel');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'event', 'channel'], 'user_notif_prefs_user_event_chan_unique');
            $table->index(['user_id', 'channel']);
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE user_notification_preferences ADD CONSTRAINT user_notification_preferences_channel_check CHECK (channel IN ('email','sms','push','in_app','webhook'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
    }
};
