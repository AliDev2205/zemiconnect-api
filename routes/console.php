<?php

use Illuminate\Support\Facades\Schedule;

/*
| Tâches planifiées (cron : * * * * * php artisan schedule:run).
*/

// Conformité : recalcul chaque nuit, après minuit heure du Bénin.
Schedule::command('zemiconnect:recalculate-compliance')
    ->dailyAt('00:30')
    ->timezone(config('zemiconnect.timezone'))
    ->withoutOverlapping()
    ->onOneServer();
