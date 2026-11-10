<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Journal d'audit (spatie/laravel-activitylog), structure du paquet
 * (y compris event et batch_uuid) + ip_address (C11), en DATETIME (C12).
 * Pas de clé étrangère vers l'objet concerné : le journal doit survivre à tout.
 * Le journal des connexions y est aussi écrit (log_name = 'connexion').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('activitylog.table_name', 'activity_log'), function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at')->nullable()->index();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('activitylog.table_name', 'activity_log'));
    }
};
