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

    // Conformité : « Expire bientôt » si l'expiration tombe dans ce délai (RG06, K2).
    'compliance' => [
        'expiry_warning_days' => 30,
    ],

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
