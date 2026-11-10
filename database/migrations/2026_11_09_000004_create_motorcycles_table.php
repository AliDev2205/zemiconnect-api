<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Motos (MLD : motorcycles). Immatriculation stockée normalisée
 * (majuscules, sans espaces ni tirets) ; châssis unique s'il est renseigné (RG10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motorcycles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_number', 20)->unique();
            $table->string('chassis_number', 50)->nullable()->unique();
            $table->string('brand', 50)->nullable();
            $table->string('model', 50)->nullable();
            $table->string('color', 30)->nullable();
            $table->unsignedSmallInteger('manufacture_year')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active')->index();
            $table->dateTime('archived_at')->nullable();
            $table->string('archive_reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        DB::statement("ALTER TABLE motorcycles ADD CONSTRAINT motorcycles_archived_has_date CHECK (status <> 'archived' OR archived_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('motorcycles');
    }
};
