<?php

namespace Tests\Unit;

use App\Services\ZemiNumberGenerator;
use Tests\TestCase;

/**
 * Format R1/B4 : ZC- + 6 chiffres aléatoires + 1 chiffre de contrôle Luhn.
 */
class ZemiNumberGeneratorTest extends TestCase
{
    private ZemiNumberGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ZemiNumberGenerator;
    }

    public function test_cle_de_luhn_sur_des_valeurs_connues(): void
    {
        // 482917 -> 2 (ZC-4829172) ; 7992739871 -> 3 (exemple de référence de l'algorithme de Luhn).
        $this->assertSame(2, ZemiNumberGenerator::luhnCheckDigit('482917'));
        $this->assertSame(3, ZemiNumberGenerator::luhnCheckDigit('7992739871'));
        $this->assertSame(0, ZemiNumberGenerator::luhnCheckDigit('000000'));
    }

    public function test_un_numero_tire_a_le_bon_format_et_une_cle_valide(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $number = $this->generator->candidate();

            $this->assertMatchesRegularExpression('/^ZC-\d{7}$/', $number);
            $this->assertTrue($this->generator->isValid($number), $number);
        }
    }

    public function test_le_prefixe_vient_de_la_configuration(): void
    {
        config(['zemiconnect.zemi_number.prefix' => 'PK-']);

        $this->assertMatchesRegularExpression('/^PK-\d{7}$/', $this->generator->candidate());
        $this->assertTrue($this->generator->isValid('PK-4829172'));
    }

    public function test_verification_de_la_cle(): void
    {
        $this->assertTrue($this->generator->isValid('ZC-4829172'));
        $this->assertFalse($this->generator->isValid('ZC-4829173')); // mauvaise clé
    }

    public function test_toute_faute_de_frappe_sur_un_chiffre_est_detectee(): void
    {
        $valid = '4829172';

        for ($position = 0; $position < 7; $position++) {
            for ($digit = 0; $digit <= 9; $digit++) {
                if ((int) $valid[$position] === $digit) {
                    continue;
                }

                $typo = substr_replace($valid, (string) $digit, $position, 1);
                $this->assertFalse($this->generator->isValid("ZC-{$typo}"), "ZC-{$typo} aurait dû être refusé");
            }
        }
    }

    public function test_inversion_de_deux_chiffres_voisins_detectee(): void
    {
        // Luhn détecte toute inversion de chiffres adjacents, sauf 09 <-> 90.
        $this->assertFalse($this->generator->isValid('ZC-8429172'));
        $this->assertFalse($this->generator->isValid('ZC-4289172'));
        $this->assertFalse($this->generator->isValid('ZC-4829712'));
    }

    public function test_formats_invalides(): void
    {
        foreach (['', 'ZC-', 'ZC-482917', 'ZC-48291720', 'ZC-48291A2', 'XX-4829172', 'abc'] as $input) {
            $this->assertFalse($this->generator->isValid($input), "« {$input} » aurait dû être refusé");
        }
    }

    public function test_saisie_tolerante_et_normalisation(): void
    {
        foreach (['ZC-4829172', 'zc-4829172', 'ZC4829172', 'zc 482 9172', ' ZC-482-9172 ', '4829172'] as $input) {
            $this->assertTrue($this->generator->isValid($input), $input);
            $this->assertSame('ZC-4829172', $this->generator->normalize($input));
        }

        $this->assertSame('BB4521RB', $this->generator->normalize('bb 4521rb'));
    }
}
