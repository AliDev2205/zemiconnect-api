<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Documents du conducteur et assurance de la moto (RG07, RG11).
 * - B2 option a : deux clés étrangères driver_id / motorcycle_id + CHECK
 *   « exactement un propriétaire, cohérent avec le type ».
 * - B3 option a : expires_at facultative pour le permis et le droit de taxi
 *   (document sans date = valide) ; obligatoire pour l'assurance.
 * - Un seul document actuel par propriétaire et par type (colonnes générées + index uniques).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('motorcycle_id')->nullable()->constrained('motorcycles')->restrictOnDelete()->restrictOnUpdate();
            $table->enum('type', ['id_card', 'driving_license', 'taxi_license', 'registration_form', 'insurance']);
            $table->string('number', 50)->nullable();
            $table->string('category', 50)->nullable();
            $table->string('issuer', 100)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->string('file_path', 255)->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->boolean('is_current')->default(true);
            $table->unsignedBigInteger('current_driver_id')->nullable()->storedAs('IF(is_current = 1, driver_id, NULL)');
            $table->unsignedBigInteger('current_motorcycle_id')->nullable()->storedAs('IF(is_current = 1, motorcycle_id, NULL)');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['driver_id', 'type']);
            $table->index(['motorcycle_id', 'type']);
            $table->index(['is_current', 'expires_at']);
            $table->unique(['current_driver_id', 'type']);
            $table->unique(['current_motorcycle_id', 'type']);
        });

        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_single_owner CHECK ((type = 'insurance' AND motorcycle_id IS NOT NULL AND driver_id IS NULL) OR (type <> 'insurance' AND driver_id IS NOT NULL AND motorcycle_id IS NULL))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_insurance_has_expiry CHECK (type <> 'insurance' OR expires_at IS NOT NULL)");
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_expiry_after_issue CHECK (expires_at IS NULL OR issued_at IS NULL OR expires_at > issued_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
