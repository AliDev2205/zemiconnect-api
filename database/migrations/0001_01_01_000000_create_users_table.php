<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Comptes Administrateur, Gestionnaire et Agent (MLD, partie 4 : users),
 * plus les tables techniques liées à l'authentification : jetons de
 * réinitialisation et sessions en base (coupure des sessions d'un compte désactivé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 191)->unique();
            $table->string('phone', 20)->nullable();
            $table->string('password', 255);
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active')->index();
            $table->boolean('must_change_password')->default(true);
            $table->string('remember_token', 100)->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_archived_has_date CHECK (status <> 'archived' OR archived_at IS NOT NULL)");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 191)->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
