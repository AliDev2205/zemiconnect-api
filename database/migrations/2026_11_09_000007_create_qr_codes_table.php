<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * QR du conducteur et leur historique (RG12, RG13, RG14).
 * Jeton sensible à la casse (utf8mb4_bin), unique sur toutes les lignes,
 * révoquées comprises : il n'est jamais réutilisé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->restrictOnDelete()->restrictOnUpdate();
            $table->string('token', 64)->collation('utf8mb4_bin')->unique();
            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->unsignedBigInteger('active_driver_id')->nullable()->storedAs("IF(status = 'active', driver_id, NULL)")->unique();
            $table->string('generation_reason', 255)->nullable();
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('revoke_reason', 255)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['driver_id', 'created_at']);
        });

        DB::statement("ALTER TABLE qr_codes ADD CONSTRAINT qr_codes_revoked_has_date CHECK ((status = 'revoked') = (revoked_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
