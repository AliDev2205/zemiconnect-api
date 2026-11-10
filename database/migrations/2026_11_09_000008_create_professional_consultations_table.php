<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Journal des consultations des agents (G6, RG17). Ajout seul : pas de updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('driver_id')->constrained('drivers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('qr_code_id')->nullable()->constrained('qr_codes')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('channel', ['qr', 'recherche']);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at')->index();

            $table->index(['agent_id', 'created_at']);
            $table->index(['driver_id', 'created_at']);
        });

        DB::statement("ALTER TABLE professional_consultations ADD CONSTRAINT consultations_qr_channel_has_qr CHECK (channel <> 'qr' OR qr_code_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_consultations');
    }
};
