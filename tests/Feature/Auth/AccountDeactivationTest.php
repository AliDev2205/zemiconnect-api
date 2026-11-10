<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Activity;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * À la désactivation (ou l'archivage) d'un compte, toutes ses sessions sont supprimées (H4, CA20).
 */
class AccountDeactivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_desactivation_supprime_toutes_les_sessions_et_le_jeton_de_reconnexion(): void
    {
        $manager = $this->userWithRole('gestionnaire');
        $agent = $this->userWithRole('agent');
        $other = $this->userWithRole('agent');
        DB::table('sessions')->insert([
            ['id' => 'tel-agent', 'user_id' => $agent->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'pc-agent', 'user_id' => $agent->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'autre', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $oldToken = $agent->remember_token;

        app(AccountService::class)->deactivate($agent, $manager);

        $agent->refresh();
        $this->assertSame(UserStatus::Inactive, $agent->status);
        $this->assertNotSame($oldToken, $agent->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $agent->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());

        $log = Activity::query()->where('log_name', 'audit')->latest('id')->first();
        $this->assertSame('user.deactivated', $log->description);
        $this->assertSame($manager->id, $log->causer_id);
    }

    public function test_archivage_supprime_les_sessions_et_date_l_archivage(): void
    {
        $agent = $this->userWithRole('agent');
        DB::table('sessions')->insert(['id' => 'tel-agent', 'user_id' => $agent->id, 'payload' => '', 'last_activity' => time()]);

        app(AccountService::class)->archive($agent);

        $agent->refresh();
        $this->assertSame(UserStatus::Archived, $agent->status);
        $this->assertNotNull($agent->archived_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $agent->id)->count());
    }

    public function test_un_compte_desactive_ne_peut_plus_rien_appeler(): void
    {
        $agent = $this->userWithRole('agent');

        app(AccountService::class)->deactivate($agent);

        $this->actingAs($agent->fresh())->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');
    }
}
