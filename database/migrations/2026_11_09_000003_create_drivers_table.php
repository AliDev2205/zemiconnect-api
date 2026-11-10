<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Dossier du conducteur (MLD : drivers).
 * - B1 option a : identification_number en clair, nullable, index non unique.
 * - CHECK liant statut et numéro ZemiConnect (RG02, RG15, C13).
 * - Déclencheur : zemi_number non modifiable une fois attribué (partie 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('zemi_number', 30)->nullable()->unique();
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->date('birth_date')->nullable();
            $table->string('identification_number', 50)->nullable()->index();
            $table->string('phone', 20)->index();
            $table->string('address', 255)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->enum('status', ['en_attente', 'actif', 'suspendu', 'archive'])->default('en_attente')->index();
            $table->string('status_reason', 255)->nullable();
            $table->enum('compliance_status', ['conforme', 'expire_bientot', 'non_conforme'])->default('non_conforme')->index();
            $table->dateTime('compliance_checked_at')->nullable();
            $table->dateTime('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('archived_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('created_at')->index();
            $table->dateTime('updated_at');

            $table->index(['last_name', 'first_name']);
            $table->index(['status', 'compliance_status']);
        });

        DB::statement("ALTER TABLE drivers ADD CONSTRAINT drivers_pending_without_number CHECK ((status = 'en_attente') = (zemi_number IS NULL))");
        DB::statement("ALTER TABLE drivers ADD CONSTRAINT drivers_validated_has_date CHECK (status = 'en_attente' OR validated_at IS NOT NULL)");
        DB::statement("ALTER TABLE drivers ADD CONSTRAINT drivers_archived_has_date CHECK (status <> 'archive' OR archived_at IS NOT NULL)");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER drivers_zemi_number_immutable
            BEFORE UPDATE ON drivers
            FOR EACH ROW
            BEGIN
                IF OLD.zemi_number IS NOT NULL AND NOT (NEW.zemi_number <=> OLD.zemi_number) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'zemi_number ne peut pas etre modifie une fois attribue';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS drivers_zemi_number_immutable');
        Schema::dropIfExists('drivers');
    }
};
