<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Historique des affectations conducteur <-> moto (RG08, RG09).
 * Les colonnes générées open_driver_id / open_motorcycle_id valent l'identifiant
 * tant que l'affectation est ouverte, NULL sinon : leurs index uniques
 * interdisent une deuxième affectation ouverte (MySQL accepte plusieurs NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_motorcycle_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('motorcycle_id')->constrained('motorcycles')->restrictOnDelete()->restrictOnUpdate();
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->string('start_reason', 255)->nullable();
            $table->string('end_reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedBigInteger('open_driver_id')->nullable()->storedAs('IF(ended_at IS NULL, driver_id, NULL)')->unique();
            $table->unsignedBigInteger('open_motorcycle_id')->nullable()->storedAs('IF(ended_at IS NULL, motorcycle_id, NULL)')->unique();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['driver_id', 'started_at']);
            $table->index(['motorcycle_id', 'started_at']);
        });

        DB::statement('ALTER TABLE driver_motorcycle_assignments ADD CONSTRAINT assignments_end_after_start CHECK (ended_at IS NULL OR ended_at >= started_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_motorcycle_assignments');
    }
};
