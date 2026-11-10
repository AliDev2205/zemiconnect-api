<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_modifie_nom_prenom_telephone_et_renvoie_me(): void
    {
        $user = $this->userWithRole('gestionnaire', ['email' => 'g@test.local']);

        $this->actingAs($user)->putJson('/api/v1/auth/profile', [
            'first_name' => 'Aïcha',
            'last_name' => 'TOKO',
            'phone' => '+229 01 97 00 00 02',
        ])->assertOk()
            ->assertJsonPath('data.user.first_name', 'Aïcha')
            ->assertJsonPath('data.user.last_name', 'TOKO')
            ->assertJsonPath('data.user.phone', '+229 01 97 00 00 02')
            ->assertJsonStructure(['data' => ['user', 'permissions']]);
    }

    public function test_email_non_modifiable(): void
    {
        $user = $this->userWithRole('gestionnaire', ['email' => 'g@test.local']);

        $this->actingAs($user)->putJson('/api/v1/auth/profile', [
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'pirate@test.local',
        ])->assertOk()->assertJsonPath('data.user.email', 'g@test.local');

        $this->assertSame('g@test.local', $user->fresh()->email);
    }

    public function test_telephone_facultatif_nom_obligatoire(): void
    {
        $user = $this->userWithRole('agent');

        $this->actingAs($user)->putJson('/api/v1/auth/profile', ['first_name' => 'A', 'last_name' => 'B', 'phone' => null])->assertOk();
        $this->actingAs($user)->putJson('/api/v1/auth/profile', ['phone' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name']);
    }
}
