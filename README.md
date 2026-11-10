# ZemiConnect API (V2)

Backend Laravel 12 du registre des conducteurs de taxi-moto ZemiConnect. Il est consommé par le frontend Next.js [`zemiconnect-web`](../zemiconnect-web).

- **Lot 1, squelette** : architecture, base de données complète du MVP, rôles et permissions, authentification, `ZemiNumberGenerator`.
- **Lot 2, cœur métier** : conducteurs (création, modification, photo, doublons, validation, statuts, historique), motos et affectations, documents et fichiers privés, conformité et recalcul nocturne.
- **À venir** : profils publics et consultation agent (lot 3), QR et badge, agents, gestionnaires, paramètres, tableau de bord, journaux.

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
php artisan schedule:work          # en local ; en production : cron « * * * * * php artisan schedule:run »
```

Tâche planifiée : `zemiconnect:recalculate-compliance`, chaque nuit à 00:30 (heure du Bénin). Elle recalcule la conformité de tous les dossiers, car le seul passage du temps la fait changer. Elle se lance aussi à la main.

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
  Console/Commands/       RecalculateCompliance (planifiée chaque nuit)
  Exceptions/             BusinessRuleException : règle métier refusée -> 422 { code }
  Http/Controllers/Api/<Domaine>/   Auth/, Drivers/ (Driver, DriverStatus, DriverHistory),
                                    Motorcycles/ (Motorcycle, Assignment), Documents/ (Document, DocumentFile)
  Http/Requests/<Domaine>/          une Form Request par écriture
  Http/Resources/Drivers/           DriverResource (gestion) ; Public et Professional au lot 3
  Http/Middleware/        EnsureAccountIsActive, EnsurePasswordChanged
  Models/                 User, AgentProfile, Driver, Motorcycle, Assignment, Document, QrCode,
                          ProfessionalConsultation, Setting, Activity
  Policies/               DriverPolicy, MotorcyclePolicy, DocumentPolicy
  Services/               AuthService, AccountService, ZemiNumberGenerator, DriverService,
                          DriverValidationService, DriverStatusService, AssignmentService,
                          MotorcycleService, DocumentService, ComplianceService, QrCodeService
  Support/                ApiResponse (format commun), Audit (écriture uniforme du journal d'audit)
config/zemiconnect.php    préfixe du numéro, seuil de 30 jours, fuseau, fichiers, limites de débit, premier admin
routes/api.php            charge routes/api/v1/<domaine>/*.php sous /api/v1 (auth, drivers, motorcycles, documents)
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
- **Règle du mot de passe** : 10 caractères, une lettre, un chiffre, comme au frontend, plus le refus des mots de passe compromis (§17, `Password::uncompromised()`, activé au lot 2). Ce contrôle interroge Have I Been Pwned par k-anonymat : seul le préfixe du hachage SHA-1 est envoyé. Le délai d'attente est de 3 s ; si le service ne répond pas, la saisie n'est pas bloquée (`NotPwnedVerifier` attrape l'erreur). Le test simule la panne par une réponse 503 : sur ce poste, PHP 8.3.0 ZTS (WAMP) plante sur `Http::failedConnection()`, un bug de PHP lui-même. Une mise à jour de PHP est recommandée.
- **Tables Spatie** : forme standard, donc les pivots gardent `ON DELETE CASCADE`. Toutes les autres clés étrangères sont en RESTRICT (vérifié par un test). Les tables techniques Laravel (`jobs`, `sessions`…) gardent leurs entiers d'horodatage standard.
- **Démonstration** : le téléphone est obligatoire en base, donc Rachidi BIO et Issa YAROU reçoivent un numéro fictif. L'assurance de la moto BB5590RB reçoit une date (+300 jours), car elle est obligatoire en base. Les immatriculations sont stockées normalisées (`BB4521RB`). Les numéros reprennent les 6 chiffres des maquettes, suivis de la clé de Luhn.
- **Numéro ZemiConnect** : l'exemple « ZC-4829173 » du cahier n'a pas une clé de Luhn valide. Le numéro correct est ZC-4829172.
- **Durcissement des droits MySQL** (retrait de DELETE et UPDATE sur les journaux, partie 5) : pas fait dans ce lot, c'est une opération d'exploitation.

## Choix du lot 2 (cœur métier)

- **Autorisations** : les Policies sont appliquées par le middleware `can:` de chaque route, donc avant la validation du corps. Un administrateur reçoit 403 sur toute écriture, même avec un corps vide ou invalide. Il lit tout, fichiers compris. L'agent n'a accès à aucune route de gestion.
- **Règles métier refusées** : 422 avec un `code` (`invalid_transition`, `activation_incomplete`, `driver_archived`, `motorcycle_archived`, `motorcycle_assigned`, `motorcycle_unavailable`…), levé par `BusinessRuleException` dans la transaction du service, qui est alors annulée. Aucun 409 n'est utilisé.
- **Transitions (GE4)** : Actif → Suspendu (motif obligatoire), Suspendu → Actif, Actif ou Suspendu → Archivé (motif obligatoire ; QR révoqué et affectation clôturée dans la même transaction). Un dossier archivé est figé : plus de transition, de modification ni de document.
- **Écart GE4 / base** : GE4 propose « Archiver » pour tous les statuts sauf Archivé, donc aussi En attente. Or le CHECK C13 (« En attente ⇔ sans numéro ») refuse un dossier archivé sans numéro. L'archivage d'un dossier En attente est donc refusé (422 `invalid_transition`). Pour l'autoriser, il faudrait modifier le CHECK, c'est-à-dire le MLD.
- **Création** : le dossier est créé En attente dès l'étape Identité. Nom, prénom, date de naissance, téléphone et adresse sont obligatoires (GE3) ; l'identifiant et la fiche papier sont facultatifs. Le téléphone est contrôlé par une forme simple (chiffres, espaces, `+`), puisque le format R5 reste ouvert.
- **Doublons** : le téléphone est comparé sur ses seuls chiffres, l'identifiant à l'identique, le nom et le prénom sans tenir compte de la casse. C'est un simple avertissement : l'enregistrement n'est jamais bloqué.
- **Validation** : une seule transaction avec verrou sur le dossier. On vérifie les éléments d'activation, on attribue le numéro, on crée le QR (16 caractères base62 aléatoires, sans image dans ce lot), on passe le dossier en Actif et on recalcule la conformité. En cas de collision sur le numéro (deux validations simultanées), la transaction est rejouée jusqu'à 3 fois.
- **Affectation** : les lignes du conducteur et de la moto sont verrouillées. Pour un changement, la date et le motif sont obligatoires ; l'ancienne affectation se clôt à la date de la nouvelle, qui ne peut être ni future ni antérieure au début de l'affectation en cours. L'index unique `open_motorcycle_id` reste le dernier rempart en cas de course.
- **Documents** : ajouter un type qui a déjà un document actuel vaut renouvellement. L'ancien passe à `is_current = 0` et son fichier est conservé. Le fichier est obligatoire pour la fiche papier, facultatif pour les autres types.
- **Fichiers** : PDF, JPG ou PNG de 5 Mo au plus. Le contenu réel (`mimes` et `mimetypes`, via finfo) et l'extension doivent concorder. Les fichiers sont stockés sur le disque privé `local` (`storage/app/private`), sous un nom aléatoire de 40 caractères. L'accès passe par `GET /documents/{id}/file` (permission vérifiée et accès journalisé), qui renvoie une URL signée de 5 minutes servie en flux. La photo suit la même règle (`photo_url` signée) ; elle est réencodée en JPEG par GD, ce qui retire les métadonnées EXIF, et l'ancienne photo est conservée.
- **Conformité** : le calcul se fait à la date du jour à l'heure du Bénin (`Africa/Porto-Novo`, configurable). Un document expire à la fin de son dernier jour : le jour J vaut donc « expire bientôt », et J+1 « expiré ». La fenêtre « expire bientôt » va de J à J+30 inclus. Le recalcul a lieu à chaque ajout de document (une assurance recalcule le conducteur de la moto), à chaque affectation, à l'archivage et chaque nuit ; il ne touche jamais au statut.
- **Journal d'audit** : `App\Support\Audit` donne une seule forme aux `properties` (`old`, `attributes`, `reason`, `driver_id`, `label`, `detail`), ce qui permet de construire le fil d'historique d'un conducteur, y compris l'assurance de sa moto. Les actions sont nommées comme dans le contrat ; deux codes sont ajoutés : `assignment.closed` et `document.file_viewed`.
- **Correction du lot 1** : avec `APP_DEBUG=false`, une `HttpResponseException` (par exemple 403 `account_disabled`) devenait un 500. Elle est maintenant renvoyée telle quelle, et les tests tournent avec `APP_DEBUG=false`, comme en production.

## Reste à faire (lots suivants)

Profils publics et consultation agent (Resources Public et Professional), régénération et révocation manuelle du QR, images PNG et SVG, badge PDF, agents, gestionnaires, paramètres, tableau de bord, journaux, paquets QR, PDF et sauvegarde.
