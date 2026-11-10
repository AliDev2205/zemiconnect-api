<?php

/*
|--------------------------------------------------------------------------
| ZemiConnect : réglages métier et techniques
|--------------------------------------------------------------------------
| Les règles fixes du MVP (seuil de 30 jours, format du numéro) vivent ici,
| pas dans la table settings, qui ne porte que les informations de la
| structure gestionnaire (conception BDD, C15).
*/

return [

    // URL du frontend Next.js : liens de réinitialisation du mot de passe.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // Origine publique des QR imprimés (<public_url>/v/<jeton>).
    'public_url' => env('PUBLIC_URL', env('FRONTEND_URL', 'http://localhost:3000')),

    // Numéro ZemiConnect (R1/B4) : préfixe + 6 chiffres aléatoires + 1 chiffre de contrôle Luhn.
    'zemi_number' => [
        'prefix' => env('ZEMI_NUMBER_PREFIX', 'ZC-'),
        'random_digits' => 6,
        'max_attempts' => 10,
    ],

    // Fuseau métier : « aujourd'hui » pour les expirations (dates stockées en UTC, affichées à l'heure du Bénin).
    'timezone' => env('ZEMICONNECT_TIMEZONE', 'Africa/Porto-Novo'),

    // Conformité : « Expire bientôt » si l'expiration tombe dans ce délai (RG06, K2).
    'compliance' => [
        'expiry_warning_days' => 30,
    ],

    // Fichiers privés (disque local = storage/app/private).
    'files' => [
        'disk' => 'local',
        'max_kilobytes' => 5 * 1024,
        // Durée de validité des URL signées (fichiers de documents, photos), en minutes.
        'signed_url_minutes' => 5,
    ],

    // Jeton du QR : 16 caractères base62 (cahier §12, proposition technique).
    'qr' => [
        'token_length' => 16,
    ],

    // Vérification des mots de passe compromis (§17) : délai maximal d'attente du service, en secondes.
    // Si le service ne répond pas, le mot de passe n'est pas bloqué.
    'uncompromised_timeout' => 3,

    'rate_limits' => [
        // Blocage temporaire de la connexion (A4) : par email + IP.
        'login' => [
            'max_attempts' => 5,
            'decay_minutes' => 15,
        ],
        // Mot de passe oublié : par IP.
        'forgot_password' => [
            'per_minute' => 5,
        ],
        // Profil public par jeton QR et recherche publique par numéro : par IP.
        'public' => [
            'per_minute' => 10,
            'per_day' => 100,
        ],
    ],

    // Premier Administrateur, créé par FirstAdminSeeder.
    'first_admin' => [
        'email' => env('FIRST_ADMIN_EMAIL'),
        'password' => env('FIRST_ADMIN_PASSWORD'),
        'first_name' => env('FIRST_ADMIN_FIRST_NAME', 'Administrateur'),
        'last_name' => env('FIRST_ADMIN_LAST_NAME', 'ZEMICONNECT'),
    ],

];
