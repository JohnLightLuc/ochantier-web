<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fin de période des abonnements promoteur : renouvellement, retard, expiration, fin après résiliation.
Schedule::command('ochantier:renew-subscriptions')->dailyAt('01:00');

// Rapports programmés : génération, bibliothèque et envoi par e-mail.
Schedule::command('ochantier:run-report-schedules')->hourly();

// Rappels aux promoteurs : paiements à régler demain ou en retard, journées de pointage de la veille à valider.
Schedule::command('ochantier:promoter-reminders')->dailyAt('08:00');

// Rappels aux commerciaux : relances conseillées, et le lundi objectif du mois et classement.
Schedule::command('ochantier:commercial-reminders')->dailyAt('08:00');

// Fin des suspensions d’ouvriers arrivées à leur date : l’ouvrier redevient validé.
Schedule::command('ochantier:lift-worker-suspensions')->hourly();
