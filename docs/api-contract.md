# Contrat de l'API ZemiConnect (v1)

Ce document décrit **l'API réelle** (`zemiconnect-api`), alignée sur la base de données (MLD de la conception BDD). Il remplace le contrat écrit pour le mode fictif du frontend et sert au lot de branchement : les services de `src/lib/api/services/` sont à adapter à ce document.

**Règle générale : les noms de champs et les valeurs exposés par l'API sont ceux de la base, sans traduction** (`zemi_number`, `identification_number`, `compliance_status`, `manufacture_year`, `status: 'active'`, `type: 'driving_license'`…).

Légende : ✅ implémenté (lot 1 : authentification ; lot 2 : conducteurs, motos, affectations, documents, conformité) · 📋 à venir (lots suivants, contrat cible).

## Changements par rapport au contrat du mode fictif

| Sujet | Ancien contrat (frontend) | API réelle |
| --- | --- | --- |
| Identifiants | chaînes (`usr-admin-1`) | entiers (`id` BIGINT) |
| Compte | `account_status: 'actif'\|'desactive'\|'archive'` | `status: 'active'\|'inactive'\|'archived'` |
| Agent | `matricule`, `structure` | `matricule`, `organization` |
| Conducteur | `zc_number`, `driver_identifier`, `compliance` | `zemi_number`, `identification_number`, `compliance_status` |
| Conducteur | `has_paper_form` | supprimé : la fiche scannée est un document `registration_form` dans `documents` |
| Moto | `year` | `manufacture_year` ; plus `status: 'active'\|'archived'`, `archive_reason` |
| Document | `type: 'piece_identite'\|'permis'\|'droit_taxi'\|'assurance'` | `type: 'id_card'\|'driving_license'\|'taxi_license'\|'registration_form'\|'insurance'` |
| Document | `owner_type`, `owner_id` | `driver_id`, `motorcycle_id` (un seul renseigné) |
| Document | `superseded_at` | supprimé : `is_current` (false = remplacé) ; l'historique se lit trié par `created_at` |
| Affectation | `reason` | `start_reason`, `end_reason` |
| QR | `status: 'actif'\|'revoque'`, `issued_at` | `status: 'active'\|'revoked'`, `created_at` ; plus `generation_reason` |
| Consultation | `consultation.search` | canal `recherche` : action `consultation.recherche` |
| Déconnexion | réponse `null` | **204** sans corps (cahier §15) |
| Changement de mot de passe | — | **déconnecte les autres sessions** (cahier §15) |
| Mot de passe provisoire | — | 403 `code: "password_change_required"` sur les routes interdites |
| Permissions | noms du frontend (`agent.drivers.view`…) | **noms du §11 du cahier** (voir plus bas) |
| Numéro ZemiConnect | « sans format imposé » | `ZC-` + 6 chiffres + 1 chiffre de contrôle Luhn |
| Badge PDF | `drivers.view` | `qr.manage` (§11 : télécharger le badge = gestionnaire seulement) |

## Conventions générales

- **Base** : `${NEXT_PUBLIC_API_URL}/api/v1` (ex. `http://localhost:8000/api/v1`).
- **Authentification** : Sanctum en mode SPA, cookie de session (`withCredentials`). Le front appelle `GET /sanctum/csrf-cookie` (hors `/api/v1`) avant la connexion ou la première écriture, puis renvoie `X-XSRF-TOKEN`. Sur un **419**, il redemande le cookie et rejoue la requête une fois. Le front doit être servi sur un domaine listé dans `SANCTUM_STATEFUL_DOMAINS` (en local : `localhost:3000`, pas `127.0.0.1`).
- **Enveloppe de réponse** :

  ```json
  { "status": "success", "message": "OK", "data": { } }
  ```

- **Pagination** (`data` des listes ; paramètres `page`, `per_page`) :

  ```json
  { "data": [ ], "total": 42, "per_page": 15, "current_page": 1, "last_page": 3 }
  ```

- **Erreurs** :

  ```json
  { "status": "error", "message": "…", "errors": { "champ": ["message"] }, "code": "…" }
  ```

  `errors` est présent sur un 422 de validation ; `code` sur certains 403 (`account_disabled`, `password_change_required`) et sur **toute règle métier refusée (422)**.

  Codes métier (422) : `invalid_transition` (transition de statut interdite, `errors.status`), `activation_incomplete` (`errors.activation`), `driver_archived`, `motorcycle_archived`, `motorcycle_assigned` (archivage d'une moto affectée), `motorcycle_unavailable` (`errors.motorcycle_id`, moto affectée à un autre conducteur), `motorcycle_already_assigned`, `assignment_change_invalid` (`errors.start_reason` / `errors.started_at`).

  | Code HTTP | Usage | Traitement côté front |
  | --- | --- | --- |
  | 401 | Session absente ou expirée | Retour à `/connexion?motif=session&retour=<page>` (jamais depuis une page publique) |
  | 403 | Droit insuffisant ; `code: "account_disabled"` = compte désactivé ou archivé ; `code: "password_change_required"` = mot de passe provisoire | Accès refusé ; `account_disabled` : déconnexion, `/connexion?motif=desactive` ; `password_change_required` : `/changer-mot-de-passe` |
  | 404 | Ressource introuvable | État vide ou PU3 |
  | 409 | Non utilisé (lot 2) : les transitions impossibles répondent 422 avec un `code` | — |
  | 419 | Jeton CSRF expiré | Nouveau cookie, requête rejouée une fois |
  | 422 | Validation (`errors` par champ) ; règle métier refusée (`code`) | Message sous chaque champ ; bandeau dans la modale pour un `code` |
  | 429 | Trop d'essais, en-tête `Retry-After` (secondes) | « Réessayez dans X minutes » |

- **Dates** : `AAAA-MM-JJ` pour les dates seules (`birth_date`, `issued_at`, `expires_at`, `started_at`, `ended_at`) ; ISO 8601 en UTC (`2026-11-04T08:12:00Z`) pour les horodatages. Une date d'expiration absente vaut `null`.

## Permissions (§11 du cahier)

Renvoyées par `GET /auth/me` ; l'API reste seule juge et répond 403. Aucune permission n'est donnée directement à un compte.

| Permission | admin | gestionnaire | agent | Portée |
| --- | --- | --- | --- | --- |
| `drivers.view_professional` | ✓ | ✓ | ✓ | informations professionnelles en texte |
| `drivers.search_exact` | ✓ | ✓ | ✓ | recherche par numéro ZemiConnect ou immatriculation exacts |
| `documents.view_file` | ✓ | ✓ | | ouvrir ou télécharger les fichiers des documents |
| `drivers.view` | ✓ | ✓ | | lister, rechercher librement, filtrer les conducteurs |
| `motorcycles.view` | ✓ | ✓ | | motos, affectations et leur historique |
| `agents.view` | ✓ | ✓ | | liste et fiches des agents |
| `dashboard.view` | ✓ | ✓ | | tableau de bord (lecture pour l'admin) |
| `consultations.view` | ✓ | ✓ | | journal des consultations |
| `audit.view` | ✓ | ✓ | | journal d'audit (gestionnaire : conducteurs, motos, agents) |
| `audit.view_all` | ✓ | | | journal d'audit complet, **dont les connexions** |
| `drivers.manage` | | ✓ | | créer, modifier, valider, suspendre, réactiver, archiver |
| `motorcycles.manage` | | ✓ | | motos |
| `assignments.manage` | | ✓ | | affectations |
| `documents.manage` | | ✓ | | documents |
| `qr.manage` | | ✓ | | générer, révoquer, régénérer un QR ; télécharger le badge |
| `agents.manage` | | ✓ | | gérer les agents |
| `managers.manage` | ✓ | | | gérer les gestionnaires |
| `settings.manage` | ✓ | | | paramètres |

Correspondance avec les anciens noms : `agent.drivers.view` → `drivers.view_professional` + `drivers.search_exact` ; `logs.view` → `consultations.view` + `audit.view` (+ `audit.view_all` pour l'onglet Connexions) ; `managers.view` → `managers.manage`.

L'administrateur **ne fait aucune opération métier** : toute écriture sur un conducteur, une moto, un document, un QR ou un agent lui répond **403**.

## Règles métier attendues de l'API

- **Statut** du conducteur (`status`) : `en_attente`, `actif`, `suspendu`, `archive`. **Conformité** (`compliance_status`) : `conforme`, `expire_bientot`, `non_conforme`. Les deux sont indépendants.
- **Conformité calculée par l'API** sur le permis (`driving_license`), le droit de taxi (`taxi_license`) et l'assurance (`insurance`) de la **moto actuelle**. Manquant ou expiré : `non_conforme`. Expiration dans les **30 jours** : `expire_bientot`. Sans date d'expiration : valide (B3). La pièce d'identité n'entre pas dans le calcul.
- **Validité d'un document** (`validity`, calculée, sans colonne) : `valide`, `expire_bientot`, `expire`, `manquant`.
- **Numéro ZemiConnect** (`zemi_number`) : `ZC-` + 6 chiffres aléatoires + 1 chiffre de contrôle Luhn (ex. `ZC-4829172`). Il est attribué à la validation (`null` avant), puis permanent, non modifiable et jamais réutilisé. Une saisie avec une clé fausse est signalée comme faute de frappe.
- **Recherche par numéro ou immatriculation** : exacte, après normalisation (majuscules, sans espaces ni tirets ; `zc 482 9172` → `ZC-4829172`, `bb 4521-rb` → `BB4521RB`).
- **Aucune suppression** : archivage ou désactivation.
- **Fichiers** : PDF, JPG ou PNG, 5 Mo au plus, jamais exposés par un chemin public : URL temporaire signée.

## Types principaux

```ts
User { id: number, first_name, last_name, email, phone|null, role: 'admin'|'gestionnaire'|'agent',
       status: 'active'|'inactive'|'archived', must_change_password: boolean,
       last_login_at|null, created_at, matricule|null, organization|null }   // matricule/organization : agents, sinon null
Me { user: User, permissions: string[] }

Driver { id, zemi_number|null, first_name, last_name, phone, address|null, birth_date|null,
         identification_number|null, photo_url|null,                 // photo_url : URL temporaire dérivée de photo_path
         status, status_reason|null, compliance_status, compliance_checked_at|null,
         motorcycle: Motorcycle|null, assignment_started_at|null,      // dérivés de l'affectation ouverte
         documents: Document[],                                        // documents actuels (is_current = true), registration_form compris
         validated_at|null, archived_at|null, created_at, updated_at }

Motorcycle { id, plate_number, brand|null, model|null, color|null, manufacture_year|null, chassis_number|null,
             status: 'active'|'archived', archived_at|null, archive_reason|null,
             driver_id|null, driver_name|null,                         // dérivés de l'affectation ouverte
             documents: Document[], created_at }

Document { id, type: 'id_card'|'driving_license'|'taxi_license'|'registration_form'|'insurance',
           driver_id|null, motorcycle_id|null, number|null, category|null, issuer|null,
           issued_at|null, expires_at|null, is_current: boolean,
           validity,                                                   // calculée
           has_file: boolean,                                          // dérivé de file_path, jamais exposé
           file_mime|null, file_size|null, created_at }

Assignment { id, driver_id, driver_name, motorcycle_id, plate_number,   // driver_name, plate_number : dérivés
             started_at, ended_at|null, start_reason|null, end_reason|null }

QrCode { id, driver_id, token, public_url,                              // public_url = <PUBLIC_URL>/v/<token>
         status: 'active'|'revoked', generation_reason|null,
         created_at, revoked_at|null, revoke_reason|null }

LogEntry { id, kind: 'audit'|'consultation'|'connexion', action, actor_name|null, actor_role|null,
           resource_type|null, resource_id|null, resource_label|null, driver_id|null, detail|null,
           old_values|null, new_values|null, reason|null, ip_address|null, created_at,
           login_email?|null, login_result?: 'reussie'|'echouee'|'bloquee', user_agent?|null }

PublicDriverProfile { zemi_number, first_name, last_name, photo_url|null, status: 'actif'|'suspendu',
                      motorcycle: { plate_number, brand|null, color|null }|null,
                      managing_structure: { name, contact|null }|null }   // aucun autre champ

AgentDriverView { zemi_number, first_name, last_name, photo_url|null, phone, address|null, birth_date|null,
                  identification_number|null, status, compliance_status,
                  motorcycle: { plate_number, brand, model, color, manufacture_year, chassis_number }|null,
                  documents: [{ type, number, issued_at, expires_at, validity }],   // sans fichier
                  consulted_at }
```

Codes d'action du journal (`LogEntry.action`) :

- conducteurs : `driver.created|updated|photo_updated|validated|suspended|reactivated|archived` ;
- documents, affectations, QR : `document.added|renewed|file_viewed`, `assignment.changed|closed`, `qr.generated|regenerated|revoked` ;
- motos : `motorcycle.created|updated|archived` ;
- agents : `agent.created|updated|deactivated|reactivated|archived|reset_link_sent` ;
- gestionnaires et paramètres : `manager.created|updated|deactivated|reactivated|archived|reset_link_sent`, `settings.updated` ;
- consultations et connexions : `consultation.qr|recherche`, `auth.login`.

Le journal des connexions est stocké dans `activity_log` (`log_name = connexion`) ; `login_result` vaut `reussie`, `echouee` (identifiants faux ou compte désactivé) ou `bloquee` (429).

## Endpoints

### Authentification et profil ✅

| Méthode | URL | Accès | Corps | Réponse | Erreurs |
| --- | --- | --- | --- | --- | --- |
| GET | `/sanctum/csrf-cookie` (hors `/api/v1`) | public | — | cookie `XSRF-TOKEN` (204) | — |
| POST | `/auth/login` | public | `{ email, password, remember }` | `Me` | 422 `errors.email` « Identifiants incorrects. » (message unique, email inconnu ou mot de passe faux) ; 403 `account_disabled` (compte désactivé ou archivé, signalé seulement avec des identifiants corrects) ; **429 + `Retry-After` après 5 échecs pour le même email depuis la même IP, pendant 15 minutes**, même avec le bon mot de passe. Chaque tentative est journalisée. |
| POST | `/auth/logout` | connecté | — | **204** sans corps | 401 |
| GET | `/auth/me` | connecté | — | `Me` | 401 ; 403 `account_disabled` |
| POST | `/auth/forgot-password` | public | `{ email }` | `data: null`, **même réponse** que l'email existe ou non (seuls les comptes actifs reçoivent le lien) | 422 format ; 429 (5 par minute et par IP) |
| POST | `/auth/reset-password` | public | `{ token, email, password, password_confirmation }` | `data: null` ; toutes les sessions du compte sont fermées ; `must_change_password` passe à `false` | 422 `errors.token` = lien invalide, expiré (60 min) ou déjà utilisé ; 422 `password` ; 429 |
| PUT | `/auth/password` | connecté | `{ current_password, password, password_confirmation }` | `data: null` ; `must_change_password` passe à `false` ; **les autres sessions sont déconnectées** | 422 `current_password` incorrect ; 422 `password` |
| PUT | `/auth/profile` | connecté | `{ first_name, last_name, phone\|null }` (email non modifiable, ignoré) | `Me` | 422 |

- **« Rester connecté »** (`remember: true`) : cookie de reconnexion de 30 jours. Sans lui, la session expire après 120 minutes d'inactivité.
- **Mot de passe** : 10 caractères au moins, une lettre, un chiffre.
- **Mot de passe provisoire** : tant que `must_change_password` vaut `true`, l'API répond 403 `password_change_required` partout sauf `/auth/password`, `/auth/me` et `/auth/logout`.
- **Compte désactivé ou archivé** : toutes ses sessions sont supprimées. Son prochain appel reçoit 403 `account_disabled`.
- **Lien de réinitialisation** : `<FRONTEND_URL>/reinitialisation?token=…&email=…`.

### Public 📋 (sans cookie, appelé côté serveur)

| Méthode | URL | Accès | Réponse | Erreurs |
| --- | --- | --- | --- | --- |
| GET | `/public/drivers/qr/{token}` | public | `PublicDriverProfile` | **404** identique pour un jeton inconnu, un QR révoqué, un conducteur en attente ou archivé ; 429 |
| GET | `/public/drivers?numero=` | public | `PublicDriverProfile` | 404 identique (introuvable, archivé, clé invalide) ; 429 |

Limitation de débit : **10 requêtes par minute et 100 par jour, par IP du client** (configurable dans `config/zemiconnect.php`). Les appels venant du serveur Next, l'API doit lire l'IP transmise (`X-Forwarded-For`, serveur Next en proxy de confiance). `managing_structure` vient des paramètres et vaut `null` s'ils sont vides.

### Consultation agent 📋

| Méthode | URL | Permission | Réponse | Erreurs |
| --- | --- | --- | --- | --- |
| GET | `/agent/drivers/qr/{token}` | `drivers.view_professional` | `AgentDriverView` | 404 QR révoqué ou inconnu ; 401 ; 403 ; 403 `account_disabled` |
| GET | `/agent/drivers/search?q=` | `drivers.search_exact` + `drivers.view_professional` | `AgentDriverView` | 404 aucune correspondance exacte ; 422 `q` si la clé du numéro est fausse (faute de frappe) |

Chaque appel enregistre la consultation (`professional_consultations`, canal `qr` ou `recherche`, agent, IP, navigateur) et renvoie son horodatage dans `consulted_at`.

### Tableau de bord 📋

| Méthode | URL | Permission | Réponse |
| --- | --- | --- | --- |
| GET | `/dashboard` | `dashboard.view` | `{ drivers_total, drivers_by_status{en_attente, actif, suspendu, archive}, active_by_compliance{conforme, expire_bientot, non_conforme}, motorcycles_total, motorcycles_unassigned, agents_active, managers_active, todo: { expired: TodoItem[], expiring: TodoItem[], pending: TodoItem[] } }` |

`TodoItem { driver_id, driver_name, document_type|null, date|null, missing?: ('photo'|'id_card'|'driving_license'|'motorcycle')[] }`.

### Conducteurs ✅

L'agent n'a accès à aucune de ces routes (403) ; l'administrateur les lit toutes et reçoit 403 sur toute écriture. Les autorisations sont vérifiées **avant** la validation du corps.

| Méthode | URL | Permission | Corps | Réponse | Erreurs |
| --- | --- | --- | --- | --- | --- |
| GET | `/drivers?search&status&compliance_status&registered_from&registered_to&sort&page&per_page` | `drivers.view` | — | `Paginated<Driver>` (sans `documents` ; `motorcycle` sans assurance) | 422 filtre invalide |
| GET | `/drivers/duplicates?phone&identification_number&first_name&last_name&exclude` | `drivers.manage` | — | `[{ id, name, zemi_number\|null, status, reasons: ('phone'\|'identifier'\|'name')[] }]` | — |
| POST | `/drivers` | `drivers.manage` | multipart : `last_name, first_name, birth_date, phone, address` (obligatoires), `identification_number?`, `registration_form?` (fichier, devient un document `registration_form`) | **201** `Driver` en attente | 422 |
| GET | `/drivers/{id}` | `drivers.view` | — | `Driver` (documents actuels, moto actuelle et son assurance) | 404 |
| PUT | `/drivers/{id}` (ou POST `?_method=PUT` en multipart) | `drivers.manage` | mêmes champs, chacun facultatif ; `registration_form?` | `Driver` | 422 `driver_archived` ; 422 |
| POST | `/drivers/{id}/photo` | `drivers.manage` | multipart `photo` (JPEG ou PNG, contenu réel, 5 Mo, ≥ 200 × 200 px) | `Driver` (photo réencodée en JPEG, l'ancienne conservée) | 422 `driver_archived` ; 422 |
| GET | `/drivers/{id}/photo?expires&signature` | URL signée (`photo_url`, 5 min) | — | image JPEG | 403 signature absente ou expirée |
| POST | `/drivers/{id}/assignment` | `assignments.manage` | `{ motorcycle_id, started_at?, start_reason? }` (`started_at` ≤ aujourd'hui ; **date et motif obligatoires pour un changement**) | `Driver` ; l'affectation en cours est clôturée à `started_at` | 422 `motorcycle_unavailable` sur `motorcycle_id` (« Cette moto est déjà affectée à un autre conducteur ») ; 422 `motorcycle_archived` ; 422 `assignment_change_invalid` ; 422 `driver_archived` |
| GET | `/drivers/{id}/assignments` | `motorcycles.view` | — | `Assignment[]` (plus récente d'abord) | — |
| POST | `/drivers/{id}/validate` | `drivers.manage` | — | `Driver` (`zemi_number` attribué, QR actif créé, statut `actif`, `validated_by`) | 422 `activation_incomplete` `{ errors.activation: ('photo'\|'id_card'\|'driving_license'\|'motorcycle')[] }` ; 422 `invalid_transition` si pas en attente |
| PATCH | `/drivers/{id}/status` | `drivers.manage` | `{ status: 'suspendu'\|'actif', reason }` (motif obligatoire pour suspendre) | `Driver` | 422 `invalid_transition` ; 422 `reason` |
| POST | `/drivers/{id}/archive` | `drivers.manage` | `{ reason }` | `Driver` (QR révoqué, affectation clôturée, même transaction) | 422 `invalid_transition` ; 422 `reason` |
| GET | `/drivers/{id}/history` | `drivers.view` | — | `LogEntry[]` (audit `kind: audit` + consultations `kind: consultation`, plus récent d'abord) | — |

Transitions (GE4) : En attente → Actif (validation) ; Actif → Suspendu ; Suspendu → Actif ; Actif ou Suspendu → Archivé. Un dossier archivé n'accepte plus aucune transition ni modification. **Écart avec GE4** : l'archivage d'un dossier En attente est refusé, car le CHECK C13 de la base (« En attente ⇔ sans numéro ») l'interdit.

Recherche `search` : nom, prénom, « prénom nom », numéro ZemiConnect (saisie normalisée), téléphone (chiffres, 4 au moins), identifiant, immatriculation de la moto actuelle. Tri `sort` : `-created_at` (défaut) ou `created_at`. `per_page` : 100 au plus.

### Documents ✅

| Méthode | URL | Permission | Corps | Réponse | Erreurs |
| --- | --- | --- | --- | --- | --- |
| POST | `/drivers/{id}/documents` (`id_card`, `driving_license`, `taxi_license`, `registration_form`), `/motorcycles/{id}/documents` (`insurance`) | `documents.manage` | multipart : `type, number?, category?, issuer?, issued_at?, expires_at?` (vide = sans expiration ; **obligatoire pour `insurance`** ; postérieure à `issued_at`), `file?` (obligatoire pour `registration_form`) | **201** `Document` ; l'ancien document actuel du même type passe à `is_current: false` | 422 `type` (« L'assurance se rattache à la moto… »), `expires_at`, `file` ; 422 `driver_archived` / `motorcycle_archived` |
| GET | `/drivers/{id}/documents/history?type=`, `/motorcycles/{id}/documents/history` | `drivers.view` / `motorcycles.view` | — | `Document[]` remplacés (`is_current: false`), par type puis plus récent d'abord | — |
| GET | `/documents/{id}/file` | `documents.view_file` | — | `{ url, expires_at }` : URL signée valable 5 minutes ; l'accès est journalisé (`document.file_viewed`) | 404 aucun fichier ; 403 agent |
| GET | `/documents/{id}/download?expires&signature` | URL signée | — | le fichier en flux (`inline`) | 403 signature absente ou expirée |

Fichiers : PDF, JPG ou PNG, 5 Mo au plus ; le **contenu réel** (finfo) et l'extension doivent concorder. Stockage sur disque privé, sous un nom aléatoire ; rien n'est supprimé au renouvellement.

### QR et badge 📋

| Méthode | URL | Permission | Corps | Réponse | Erreurs |
| --- | --- | --- | --- | --- | --- |
| GET | `/drivers/{id}/qr-codes` | `drivers.view` | — | `QrCode[]` (un seul `active`) | — |
| POST | `/drivers/{id}/qr-codes/regenerate` | `qr.manage` | `{ reason }` | `QrCode` | 409 en attente ou archivé ; 422 |
| POST | `/drivers/{id}/qr-codes/revoke` | `qr.manage` | `{ reason }` | `QrCode` révoqué | 409 aucun QR actif ; 422 |
| GET | `/drivers/{id}/qr-codes/active.png`, `.svg` | `qr.manage` | — | image | 404 |
| GET | `/drivers/{id}/badge.pdf` | `qr.manage` | — | PDF du badge | 404 |

### Motos ✅

| Méthode | URL | Permission | Corps | Réponse | Erreurs |
| --- | --- | --- | --- | --- | --- |
| GET | `/motorcycles?search&unassigned&status&page&per_page` | `motorcycles.view` | — | `Paginated<Motorcycle>` (`documents` = assurance actuelle ; `unassigned=true` : sans conducteur et non archivées) | — |
| POST | `/motorcycles` | `motorcycles.manage` | `{ plate_number, brand\|null, model\|null, color\|null, manufacture_year\|null, chassis_number\|null }` | **201** `Motorcycle` (immatriculation normalisée : `bb 4521-rb` → `BB4521RB`) | 422 `plate_number` ou `chassis_number` déjà existants |
| GET | `/motorcycles/{id}` | `motorcycles.view` | — | `Motorcycle` | 404 |
| PUT | `/motorcycles/{id}` | `motorcycles.manage` | comme la création, champs facultatifs | `Motorcycle` | 422 `motorcycle_archived` ; 422 |
| POST | `/motorcycles/{id}/archive` | `motorcycles.manage` | `{ archive_reason }` | `Motorcycle` | **422 `motorcycle_assigned` tant que la moto est affectée** ; 422 `motorcycle_archived` ; 422 `archive_reason` |
| GET | `/motorcycles/{id}/assignments` | `motorcycles.view` | — | `Assignment[]` (plus récente d'abord) | — |

### Agents 📋

| Méthode | URL | Permission | Corps | Réponse | Erreurs |
| --- | --- | --- | --- | --- | --- |
| GET | `/agents?search&status&page&per_page` | `agents.view` | — | `Paginated<User>` | — |
| POST | `/agents` | `agents.manage` | `{ first_name, last_name, email, phone\|null, matricule, organization\|null }` | `User` (`must_change_password: true`, email d'invitation) | 422 `email` ou `matricule` |
| GET | `/agents/{id}` | `agents.view` | — | `User` | 404 |
| PUT | `/agents/{id}` | `agents.manage` | comme la création | `User` | 409 archivé ; 422 |
| PATCH | `/agents/{id}/status` | `agents.manage` | `{ status: 'active'\|'inactive' }` | `User` ; `inactive` = **sessions supprimées immédiatement** | 409 |
| POST | `/agents/{id}/archive` | `agents.manage` | — | `User` | 409 |
| POST | `/agents/{id}/reset-link` | `agents.manage` | — | `data: null` | 409 compte non actif |

### Gestionnaires 📋 (administrateur)

| Méthode | URL | Permission | Corps | Réponse | Erreurs |
| --- | --- | --- | --- | --- | --- |
| GET | `/admin/managers?search&status&page&per_page` | `managers.manage` | — | `Paginated<User>` (gestionnaires uniquement) | — |
| POST | `/admin/managers` | `managers.manage` | `{ first_name, last_name, email, phone\|null }` | `User` (mot de passe provisoire par email) | 422 `email` |
| GET | `/admin/managers/{id}` | `managers.manage` | — | `User` | 404 |
| PUT | `/admin/managers/{id}` | `managers.manage` | comme la création | `User` | 409 ; 422 |
| PATCH | `/admin/managers/{id}/status` | `managers.manage` | `{ status: 'active'\|'inactive', reason\|null }` (motif obligatoire pour désactiver) | `User` ; `inactive` = sessions supprimées | 409 propre compte ou archivé ; 422 |
| POST | `/admin/managers/{id}/archive` | `managers.manage` | `{ reason }` | `User` | 409 ; 422 |
| POST | `/admin/managers/{id}/reset-link` | `managers.manage` | — | `data: null` | 409 |

Garde-fous : un administrateur ne peut ni se désactiver ni s'archiver lui-même, et le dernier administrateur actif ne peut pas être désactivé (RG19).

### Paramètres 📋 (administrateur)

| Méthode | URL | Permission | Corps | Réponse | Erreurs |
| --- | --- | --- | --- | --- | --- |
| GET | `/admin/settings` | `settings.manage` | — | `{ "structure.name": string\|null, "structure.contact": string\|null, expiry_warning_days: 30 }` | — |
| PUT | `/admin/settings` | `settings.manage` | `{ "structure.name"\|null, "structure.contact"\|null }` | idem | 422 |

Les clés sont celles de la table `settings`. `expiry_warning_days` est une constante (30), non modifiable.

### Journaux 📋 (lecture seule)

| Méthode | URL | Permission | Paramètres | Réponse |
| --- | --- | --- | --- | --- |
| GET | `/logs` | `audit.view` (audit), `consultations.view` (consultation), `audit.view_all` (connexion) | `kind`, `actor`, `action`, `role`, `result` (`reussie`, `echouee`, `bloquee`), `driver`, `driver_id`, `start_date`, `end_date`, `page`, `per_page` | `Paginated<LogEntry>` (plus récent d'abord) |
| GET | `/logs/actors?kind=` | idem | `kind` | `string[]` |

Le gestionnaire voit l'audit de son périmètre et les consultations ; seul l'administrateur voit les connexions. Il n'existe aucune route de modification, de suppression ni d'export.
