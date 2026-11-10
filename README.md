# ZemiConnect API (V2)

Backend Laravel 12 du registre des conducteurs de taxi-moto ZemiConnect. Il est consommé par le frontend Next.js [`zemiconnect-web`](../zemiconnect-web).

**Lot 1, squelette** : architecture, base de données complète du MVP, rôles et permissions, authentification, `ZemiNumberGenerator`. Les fonctions métier (conducteurs, motos, documents, QR, agents, journaux…) arrivent dans les lots suivants.

Sources : `docs/Cahier des charges ZemiConnect V2 (2).docx` (règles métier), `docs/Conception base de données ZemiConnect V2 (MVP).docx` (MLD, référence des migrations ; B1, B2 et B3 tranchés en option a), `docs/api-contract.md` (contrat réel de l'API), `docs/ZemiConnect V2 UX, design system et squelette.pdf` (partie 6 : arborescence).

## Stack

| Élément | Choix |
| --- | --- |
| Langage | PHP 8.3+, Laravel 12 |
| Base | MySQL 8.0.16+ (CHECK appliqués), InnoDB imposé, utf8mb4, dates en UTC (`DATETIME`) |
| Authentification | `laravel/sanctum` en mode SPA par cookie, sessions en base |
| Rôles | `spatie/laravel-permission` : `admin`, `gestionnaire`, `agent` |
| Audit | `spatie/laravel-activitylog` + colonne `ip_address` |
| Files, cache, sessions | base MySQL (pas de Redis) |
| Tests | PHPUnit, sur MySQL (pas de SQLite) |

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Créer les deux bases (utf8mb4) :

```sql
CREATE DATABASE zemiconnect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE zemiconnect_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Renseigner `DB_*` et `FIRST_ADMIN_*` dans `.env`, puis :

```bash
php artisan migrate:fresh --seed   # en local : rôles, premier admin et données de démonstration
php artisan serve                  # http://localhost:8000
php artisan queue:work             # emails (réinitialisation du mot de passe)
```

Le compte MySQL des migrations doit pouvoir créer un déclencheur (`TRIGGER` ; avec la journalisation binaire active, `SUPER` ou `log_bin_trust_function_creators=1`).

## Base de test

Les tests tournent sur **MySQL**, base `zemiconnect_test` (voir `phpunit.xml`). SQLite n'est pas possible : les CHECK, les colonnes générées et le déclencheur doivent être testés. `RefreshDatabase` rejoue toutes les migrations une fois par exécution.

## Commandes

| Commande | Rôle |
| --- | --- |
| `php artisan test` | tous les tests |
| `php artisan test --testsuite=Unit` | tests unitaires seuls (sans base) |
| `php artisan test --filter=ConstraintsTest` | contraintes de la base |
| `vendor/bin/pint` | formatage (Pint) ; `--test` pour vérifier sans modifier |
| `php artisan migrate:fresh --seed` | base locale remise à zéro avec la démonstration |
| `php artisan db:seed --class=FirstAdminSeeder` | (re)créer le premier administrateur |

## Comptes

**Premier administrateur** (`FirstAdminSeeder`, tous environnements) : email et mot de passe lus dans `.env` (`FIRST_ADMIN_EMAIL`, `FIRST_ADMIN_PASSWORD`). Le mot de passe est provisoire et doit être changé à la première connexion. Par défaut : `admin@zemiconnect.local` / `ChangerMoi2026`.

**Démonstration** (`DemoDataSeeder`, environnement `local` uniquement) : mêmes comptes que les données fictives du frontend. Mot de passe commun : `demo-zc-2026`.

| Email | Rôle | État |
| --- | --- | --- |
| `kofi.dossou@zemiconnect.exemple` | admin | actif |
| `aicha.toko@zemiconnect.exemple` | gestionnaire | actif |
| `sylvain.hounkpatin@zemiconnect.exemple` | gestionnaire | désactivé (403 `account_disabled`) |
| `bachirou.sanni@zemiconnect.exemple` | agent | actif (AG-0142) |
| `florent.agbo@zemiconnect.exemple` | agent | actif, **mot de passe à changer** |
| `chantal.seidou@zemiconnect.exemple` | agent | désactivé |
| `ousmane.adam@zemiconnect.exemple` | agent | archivé |

Conducteurs de démonstration (mêmes cas que le frontend), avec les jetons QR `demo-*` :

| Conducteur | Numéro | Cas |
| --- | --- | --- |
| Moussa ALASSANE | ZC-4829172 | actif, conforme ; permis sans date d'expiration ; QR révoqué, affectation et droit de taxi en historique |
| Ibrahim SALIFOU | ZC-3056116 | actif, expire bientôt (assurance dans 7 jours) |
| Rachidi BIO | ZC-7712045 | suspendu et non conforme |
| Saka GOUNOU | — | en attente, incomplet |
| Issa YAROU | ZC-6644306 | archivé, QR révoqué |
| Kassim ORÉ | ZC-5590138 | actif, non conforme (droit de taxi manquant) |
| Gildas KOUAGOU | ZC-8402661 | actif sans moto (non conforme) |
| Yacoubou BANI | ZC-2177459 | actif, documents sans date d'expiration |
| Fousséni TANKO | — | en attente, complet (prêt à valider) |

Le blocage après 5 échecs se démontre avec n'importe quel compte : l'adresse spéciale `bloque@…` du mode fictif n'existe pas en réel.

## Brancher le frontend

1. API : `php artisan serve` (port 8000). Dans `.env` : `FRONTEND_URL=http://localhost:3000`, `SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost`, `SESSION_DOMAIN=localhost`.
2. Frontend (`zemiconnect-web/.env.local`) :

   ```dotenv
   NEXT_PUBLIC_API_URL=http://localhost:8000
   NEXT_PUBLIC_API_MOCK=false
   NEXT_PUBLIC_PUBLIC_URL=http://localhost:3000
   ```

3. Ouvrir le frontend sur **`http://localhost:3000`**, pas sur `127.0.0.1` : le cookie de session est posé pour `localhost`.
4. Le frontend appelle `GET /sanctum/csrf-cookie`, puis `POST /api/v1/auth/login` avec `X-XSRF-TOKEN` et `withCredentials`.

Le contrat réel est dans [`docs/api-contract.md`](docs/api-contract.md), copié dans `zemiconnect-web/docs/`. Les noms de champs et les valeurs sont ceux de la base (`zemi_number`, `status: active`…), et les services du frontend sont à adapter au lot de branchement.

## Architecture

```text
app/
  Enums/                  DriverStatus, ComplianceStatus, DocumentType, DocumentValidity, QrStatus,
                          UserStatus, ConsultationChannel (+ MotorcycleStatus) : valeurs identiques à la base
  Http/Controllers/Api/<Domaine>/   Auth/ : Login, Logout, Me, Password, PasswordReset, Profile
  Http/Requests/<Domaine>/          une Form Request par écriture
  Http/Middleware/        EnsureAccountIsActive, EnsurePasswordChanged
  Models/                 User, AgentProfile, Driver, Motorcycle, Assignment, Document, QrCode,
                          ProfessionalConsultation, Setting, Activity
  Services/               AuthService, AccountService, ZemiNumberGenerator
  Support/ApiResponse.php format commun { status, message, data } / { status, message, errors?, code? }
config/zemiconnect.php    préfixe du numéro, seuil de 30 jours, limites de débit, premier admin
routes/api.php            charge routes/api/v1/<domaine>/*.php sous /api/v1
```

## Choix faits dans ce lot

Choix imposés ou validés :

- La base suit exactement le MLD. L'API expose les mêmes noms de champs et les mêmes valeurs, sans traduction.
- Pas de Repository, DTO, Action ni Event. Les services portent les opérations sur plusieurs tables. Pas de SoftDeletes : on archive.
- `App\Models\Activity` étend le modèle du paquet pour une seule raison : remplir `ip_address` automatiquement.
- `ApiResponse` est une classe à méthodes statiques, pas un trait comme dans HealthBook, pour servir aussi au rendu des exceptions.

Points mineurs, tranchés au plus simple :

- **Permissions** : le §11 ne nomme que `drivers.view_professional` et `documents.view_file`. Les autres lignes suivent le même modèle `<objet>.<action>`, une par ligne de la matrice. « Gérer motos, affectations, documents » devient trois permissions : `motorcycles.manage`, `assignments.manage`, `documents.manage`. Le journal d'audit en donne deux : `audit.view` (périmètre du gestionnaire) et `audit.view_all` (administrateur, connexions comprises).
- **Journal des connexions** : il est écrit dans `activity_log`, avec `log_name = connexion`, `event = auth.login` et `properties.result` à `reussie`, `echouee` ou `bloquee`. Il n'y a pas de table dédiée.
- **Blocage de connexion** : 5 échecs par couple email + IP, puis 15 minutes (A4). Le 6ᵉ essai renvoie 429 avec `Retry-After`, même si le mot de passe est bon.
- **Compte désactivé** : il n'est signalé (403) qu'après des identifiants corrects, pour ne pas révéler l'existence du compte.
- **« Rester connecté »** : cookie de reconnexion Laravel valable 30 jours (`AUTH_REMEMBER_DAYS`). Une session normale dure 120 minutes.
- **Désactivation ou archivage d'un compte** (`AccountService`) : suppression des lignes `sessions` du compte et régénération de `remember_token`. Le middleware `EnsureAccountIsActive` coupe aussi toute session restante.
- **Changement de mot de passe** : déconnecte les autres sessions (§15) ; la session courante reste ouverte.
- **Réinitialisation** : supprime toutes les sessions et lève `must_change_password`. Seuls les comptes actifs reçoivent un lien ; la réponse est la même dans tous les cas. Le lien est valable 60 minutes et pointe vers `<FRONTEND_URL>/reinitialisation?token=…&email=…`.
- **Mot de passe provisoire** : tant qu'il n'est pas changé, toute route autre que `me`, `logout` et `password` renvoie 403 `password_change_required`.
- **Mot de passe oublié** : limité à 5 requêtes par minute et par IP.
- **Routes publiques** (lots suivants) : 10 requêtes par minute et 100 par jour, par IP (limiteur `public`).
- **Règle du mot de passe** : 10 caractères, une lettre, un chiffre, comme au frontend. Le refus des mots de passe compromis évoqué au §17 n'est pas activé, car il appelle un service externe.
- **Tables Spatie** : forme standard, donc les pivots gardent `ON DELETE CASCADE`. Toutes les autres clés étrangères sont en RESTRICT (vérifié par un test). Les tables techniques Laravel (`jobs`, `sessions`…) gardent leurs entiers d'horodatage standard.
- **Démonstration** : le téléphone est obligatoire en base, donc Rachidi BIO et Issa YAROU reçoivent un numéro fictif. L'assurance de la moto BB5590RB reçoit une date (+300 jours), car elle est obligatoire en base. Les immatriculations sont stockées normalisées (`BB4521RB`). Les numéros reprennent les 6 chiffres des maquettes, suivis de la clé de Luhn.
- **Numéro ZemiConnect** : l'exemple « ZC-4829173 » du cahier n'a pas une clé de Luhn valide. Le numéro correct est ZC-4829172.
- **Durcissement des droits MySQL** (retrait de DELETE et UPDATE sur les journaux, partie 5) : pas fait dans ce lot, c'est une opération d'exploitation.

## Reste à faire (lots suivants)

Conducteurs (validation transactionnelle avec `ZemiNumberGenerator`), motos et affectations, documents et fichiers privés, conformité et recalcul nocturne, QR, badge PDF, profils publics et consultation agent, agents, gestionnaires, paramètres, tableau de bord, journaux, ainsi que les paquets QR, PDF et sauvegarde.
