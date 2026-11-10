<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Services\ZemiNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Génération contre la base : jamais un numéro déjà attribué (archivés compris).
 */
class ZemiNumberGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_genere_un_numero_libre_et_valide(): void
    {
        $generator = app(ZemiNumberGenerator::class);
        $number = $generator->generate();

        $this->assertTrue($generator->isValid($number));
        $this->assertFalse(Driver::query()->where('zemi_number', $number)->exists());
    }

    public function test_nouvelle_tentative_en_cas_de_collision(): void
    {
        $taken = Driver::factory()->active()->create()->zemi_number;
        $free = 'ZC-4829172';

        $generator = Mockery::mock(ZemiNumberGenerator::class)->makePartial();
        $generator->shouldReceive('candidate')->andReturn($taken, $taken, $free);

        $this->assertSame($free, $generator->generate());
    }

    public function test_un_numero_de_dossier_archive_n_est_jamais_reutilise(): void
    {
        $archived = Driver::factory()->active()->create();
        $archived->forceFill(['status' => 'archive', 'archived_at' => now()])->save();

        $generator = Mockery::mock(ZemiNumberGenerator::class)->makePartial();
        $generator->shouldReceive('candidate')->andReturn($archived->zemi_number, 'ZC-4829172');

        $this->assertSame('ZC-4829172', $generator->generate());
    }

    public function test_echec_explicite_apres_le_nombre_maximal_de_tentatives(): void
    {
        config(['zemiconnect.zemi_number.max_attempts' => 3]);
        $taken = Driver::factory()->active()->create()->zemi_number;

        $generator = Mockery::mock(ZemiNumberGenerator::class)->makePartial();
        $generator->shouldReceive('candidate')->times(3)->andReturn($taken);

        $this->expectException(RuntimeException::class);
        $generator->generate();
    }
}
