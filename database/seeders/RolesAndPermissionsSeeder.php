<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles et permissions du §11 du cahier des charges.
 *
 * Le §11 nomme drivers.view_professional et documents.view_file ; les autres
 * lignes de la matrice sont nommées sur le même modèle <objet>.<action>,
 * une permission par ligne. « Gérer motos, affectations, documents » est
 * scindée en trois permissions pour que chaque route vérifie la sienne.
 * Aucune permission n'est donnée directement à un compte (MVP).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const ROLES = ['admin', 'gestionnaire', 'agent'];

    /**
     * Permission => rôles. Lecture seule pour l'administrateur : aucune écriture métier.
     *
     * @var array<string, list<string>>
     */
    public const MATRIX = [
        // Informations professionnelles en texte (agent : après connexion, consultation journalisée)
        'drivers.view_professional' => ['agent', 'gestionnaire', 'admin'],
        // Recherche par numéro ZemiConnect ou immatriculation exacts
        'drivers.search_exact' => ['agent', 'gestionnaire', 'admin'],
        // Ouvrir ou télécharger les fichiers des documents (jamais l'agent)
        'documents.view_file' => ['gestionnaire', 'admin'],
        // Lister, rechercher librement, filtrer les conducteurs
        'drivers.view' => ['gestionnaire', 'admin'],
        // Consulter les motos, les affectations et leur historique
        'motorcycles.view' => ['gestionnaire', 'admin'],
        // Consulter les agents (liste et fiches)
        'agents.view' => ['gestionnaire', 'admin'],
        // Créer, modifier, valider, suspendre, réactiver, archiver un conducteur
        'drivers.manage' => ['gestionnaire'],
        // Gérer motos, affectations, documents
        'motorcycles.manage' => ['gestionnaire'],
        'assignments.manage' => ['gestionnaire'],
        'documents.manage' => ['gestionnaire'],
        // Générer, révoquer, régénérer un QR ; télécharger le badge
        'qr.manage' => ['gestionnaire'],
        // Gérer les agents
        'agents.manage' => ['gestionnaire'],
        // Gérer les gestionnaires
        'managers.manage' => ['admin'],
        // Tableau de bord (administrateur : lecture)
        'dashboard.view' => ['gestionnaire', 'admin'],
        // Journal des consultations
        'consultations.view' => ['gestionnaire', 'admin'],
        // Journal d'audit : gestionnaire = actions de son périmètre (conducteurs, motos, agents)
        'audit.view' => ['gestionnaire', 'admin'],
        // Journal d'audit complet, dont les connexions : administrateur
        'audit.view_all' => ['admin'],
        // Paramètres
        'settings.manage' => ['admin'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::MATRIX) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::ROLES as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions(array_keys(array_filter(
                self::MATRIX,
                fn (array $roles) => in_array($roleName, $roles, true),
            )));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
