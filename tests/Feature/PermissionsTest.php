<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsRegistry;
use Tests\TestCase;

/**
 * Chaque rôle (et le visiteur non connecté) × chaque route existante de /api/v1.
 * Les requêtes sont envoyées sans corps : un 422 (ou un 200 pour une lecture
 * ou une modification vide) prouve que l'accès est accordé, un 401/403 qu'il
 * est refusé. Les paramètres {driver}, {motorcycle} et {document} désignent
 * un dossier En attente incomplet, une moto libre et un document avec fichier.
 *
 * Toute nouvelle route doit être ajoutée à EXPECTED, sinon
 * test_toutes_les_routes_sont_couvertes échoue.
 */
class PermissionsTest extends TestCase
{
    use BuildsRegistry, RefreshDatabase;

    private const ACTORS = ['visiteur', 'admin', 'gestionnaire', 'agent'];

    /**
     * Route => statut attendu par acteur (visiteur, admin, gestionnaire, agent).
     * Administrateur : lecture partout (fichiers compris), 403 sur toute écriture métier.
     * Agent : aucune route de gestion (sa consultation arrive au lot 3).
     *
     * @var array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    private const EXPECTED = [
        // Authentification
        'POST api/v1/auth/login' => [422, 422, 422, 422],
        'POST api/v1/auth/forgot-password' => [422, 422, 422, 422],
        'POST api/v1/auth/reset-password' => [422, 422, 422, 422],
        'POST api/v1/auth/logout' => [401, 204, 204, 204],
        'GET api/v1/auth/me' => [401, 200, 200, 200],
        'PUT api/v1/auth/password' => [401, 422, 422, 422],
        'PUT api/v1/auth/profile' => [401, 422, 422, 422],
        // Conducteurs
        'GET api/v1/drivers' => [401, 200, 200, 403],
        'POST api/v1/drivers' => [401, 403, 422, 403],
        'GET api/v1/drivers/duplicates' => [401, 403, 200, 403],
        'GET api/v1/drivers/{driver}' => [401, 200, 200, 403],
        'PUT api/v1/drivers/{driver}' => [401, 403, 200, 403],
        'POST api/v1/drivers/{driver}/photo' => [401, 403, 422, 403],
        'GET api/v1/drivers/{driver}/photo' => [403, 403, 403, 403], // URL signée uniquement
        'POST api/v1/drivers/{driver}/validate' => [401, 403, 422, 403],
        'PATCH api/v1/drivers/{driver}/status' => [401, 403, 422, 403],
        'POST api/v1/drivers/{driver}/archive' => [401, 403, 422, 403],
        'GET api/v1/drivers/{driver}/history' => [401, 200, 200, 403],
        'POST api/v1/drivers/{driver}/assignment' => [401, 403, 422, 403],
        'GET api/v1/drivers/{driver}/assignments' => [401, 200, 200, 403],
        'POST api/v1/drivers/{driver}/documents' => [401, 403, 422, 403],
        'GET api/v1/drivers/{driver}/documents/history' => [401, 200, 200, 403],
        // Motos et affectations
        'GET api/v1/motorcycles' => [401, 200, 200, 403],
        'POST api/v1/motorcycles' => [401, 403, 422, 403],
        'GET api/v1/motorcycles/{motorcycle}' => [401, 200, 200, 403],
        'PUT api/v1/motorcycles/{motorcycle}' => [401, 403, 200, 403],
        'POST api/v1/motorcycles/{motorcycle}/archive' => [401, 403, 422, 403],
        'GET api/v1/motorcycles/{motorcycle}/assignments' => [401, 200, 200, 403],
        'POST api/v1/motorcycles/{motorcycle}/documents' => [401, 403, 422, 403],
        'GET api/v1/motorcycles/{motorcycle}/documents/history' => [401, 200, 200, 403],
        // Fichiers des documents
        'GET api/v1/documents/{document}/file' => [401, 200, 200, 403],
        'GET api/v1/documents/{document}/download' => [403, 403, 403, 403], // URL signée uniquement
    ];

    public static function cases(): iterable
    {
        foreach (self::EXPECTED as $route => $statuses) {
            foreach (self::ACTORS as $index => $actor) {
                yield "{$actor} {$route}" => [$route, $actor, $statuses[$index]];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_acces_par_role_et_par_route(string $route, string $actor, int $expected): void
    {
        [$method, $uri] = explode(' ', $route, 2);

        if (str_contains($uri, '{')) {
            Storage::fake('local');
            $driver = $this->pendingDriver();
            $uri = strtr($uri, [
                '{driver}' => (string) $driver->id,
                '{motorcycle}' => (string) $this->motorcycle()->id,
                '{document}' => (string) $this->addDocument($driver, DocumentType::IdCard, withFile: true)->id,
            ]);
        }

        if ($actor !== 'visiteur') {
            $this->actingAs($actor === 'gestionnaire' ? $this->manager() : $this->userWithRole($actor));
        }

        $this->json($method, '/'.$uri)->assertStatus($expected);
    }

    public function test_toutes_les_routes_sont_couvertes(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(fn ($route) => collect($route->methods())
                ->reject(fn ($method) => $method === 'HEAD')
                ->map(fn ($method) => "{$method} {$route->uri()}"))
            ->sort()
            ->values()
            ->all();

        $expected = array_keys(self::EXPECTED);
        sort($expected);

        $this->assertSame($expected, $routes);
    }

    /**
     * Matrice du §11 du cahier : l'administrateur n'a aucune permission d'écriture métier,
     * l'agent n'a que la consultation professionnelle et la recherche exacte.
     */
    public function test_matrice_des_permissions_par_role(): void
    {
        $this->seedRoles();

        $expected = [
            'admin' => [
                'agents.view', 'audit.view', 'audit.view_all', 'consultations.view', 'dashboard.view',
                'documents.view_file', 'drivers.search_exact', 'drivers.view', 'drivers.view_professional',
                'managers.manage', 'motorcycles.view', 'settings.manage',
            ],
            'gestionnaire' => [
                'agents.manage', 'agents.view', 'assignments.manage', 'audit.view', 'consultations.view',
                'dashboard.view', 'documents.manage', 'documents.view_file', 'drivers.manage',
                'drivers.search_exact', 'drivers.view', 'drivers.view_professional', 'motorcycles.manage',
                'motorcycles.view', 'qr.manage',
            ],
            'agent' => ['drivers.search_exact', 'drivers.view_professional'],
        ];

        foreach ($expected as $role => $permissions) {
            $actual = Role::findByName($role, 'web')->permissions->pluck('name')->sort()->values()->all();
            $this->assertSame($permissions, $actual, "Permissions du rôle {$role}");
        }
    }

    public function test_aucune_permission_d_ecriture_metier_pour_l_administrateur(): void
    {
        $admin = $this->userWithRole('admin');

        foreach (['drivers.manage', 'motorcycles.manage', 'assignments.manage', 'documents.manage', 'qr.manage', 'agents.manage'] as $permission) {
            $this->assertFalse($admin->can($permission), $permission);
        }
    }

    public function test_seeder_idempotent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(3, Role::query()->count());
        $this->assertSame(count(RolesAndPermissionsSeeder::MATRIX), Permission::query()->count());
        $this->assertInstanceOf(User::class, User::factory()->role('agent')->create());
    }
}
