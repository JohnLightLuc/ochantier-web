<?php

namespace Database\Seeders;

use App\Models\Integration;
use App\Models\SettingChange;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Paramètres : comptes administratifs, services connectés et historique du journal d’audit (maquette).
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['N. Yao', 'n.yao@ochantier.ci', '+225 07 08 45 12 90', 'Super administrateur', 'Actif', 'Tous', '2026-09-23 08:02', true],
            ['Awa Bamba', 'a.bamba@ochantier.ci', '+225 05 44 18 22 71', 'Responsable conformité', 'Actif', 'Avis & Litiges, Promoteurs, Paramètres', '2026-09-23 07:48', true],
            ['M. Diabaté', 'm.diabate@ochantier.ci', '+225 07 58 21 44 10', 'Responsable financier', 'Actif', 'Paiements, Finance & Reporting', '2026-09-22 18:31', true],
            ['A. Koné', 'a.kone@ochantier.ci', '+225 01 02 33 45 67', 'Responsable opérations', 'Actif', 'Chantiers, Matching, Affectations, Pointages', '2026-09-23 07:15', true],
            ['Aminata Kouassi', 'a.kouassi@ochantier.ci', '+225 07 11 90 34 28', 'Administrateur', 'Actif', 'Tous sauf Finance', '2026-09-21 16:02', true],
            ['Serge Tanoh', 's.tanoh@ochantier.ci', '+225 05 66 71 23 09', 'Support', 'Actif', 'Promoteurs, Ouvriers', '2026-09-23 09:40', false],
            ['Clarisse Ahoua', 'c.ahoua@ochantier.ci', '+225 07 44 12 08 55', 'Responsable litiges', 'Actif', 'Avis & Litiges', '2026-09-19 11:25', true],
            ['Yannick Gbagbo', 'y.gbagbo@ochantier.ci', '+225 01 70 22 18 93', 'Lecture seule', 'Suspendu', 'Tableau de bord', '2026-07-30 10:10', false],
            ['Fatou Traoré', 'f.traore@ochantier.ci', '+225 07 91 55 20 14', 'Responsable opérations', 'Invitation envoyée', 'Chantiers', null, false],
        ];
        foreach ($users as [$name, $email, $phone, $role, $status, $modules, $last, $tfa]) {
            $attributes = ['name' => $name, 'phone' => $phone, 'role' => $role, 'status' => $status, 'modules' => $modules, 'two_factor' => $tfa, 'last_login_at' => $last, 'invitation_expires_on' => $status === 'Invitation envoyée' ? '2026-09-25' : null];
            $user = User::query()->where('email', $email)->first();
            $user ? $user->update($attributes) : User::factory()->create(['email' => $email, ...$attributes]);
        }

        $integrations = [
            ['Orange Money API', 'Paiement', 'Actif', 'Production', 'api.orange.ci/omcoreapis/v1', 'OM-CLI-7741', '2026-12-31', 180, 0.2],
            ['MTN Mobile Money', 'Paiement', 'Actif', 'Production', 'momodeveloper.mtn.ci/collection/v1', 'MTN-SUB-3309', '2027-03-31', 240, 0.4],
            ['Moov Money', 'Paiement', 'Actif', 'Production', 'api.moov-africa.ci/flooz/v2', 'MOOV-8812', '2026-11-30', 310, 1.1],
            ['Wave CI', 'Paiement', 'Actif', 'Production', 'api.wave.com/v1/checkout', 'WAVE-API-5520', '2026-10-05', 150, 0.1],
            ['CinetPay (carte bancaire)', 'Paiement', 'Actif', 'Production', 'api-checkout.cinetpay.com/v2', 'CP-SITE-9021', '2027-06-30', 420, 0.6],
            ['SGBCI (virements)', 'Paiement', 'Actif', 'Production', 'sftp.sgbci.ci/releves', 'SGB-EDI-114', '2027-01-15', 900, 0],
            ['Passerelle SMS', 'SMS', 'Actif', 'Production', 'sms.gateway.ci/api/v2/send', 'SMS-OCH-01', '2027-02-28', 260, 0.8],
            ['E-mail transactionnel', 'E-mail', 'Actif', 'Production', 'smtp.mail-relay.ci:587', 'MAIL-OCH-02', '2027-05-31', 120, 0.2],
            ['Notifications push', 'Push', 'Actif', 'Production', 'push.notify-service.io/v1', 'PUSH-OCH-app', '2027-04-30', 90, 0.1],
            ['Cartographie', 'Cartographie', 'Actif', 'Production', 'tiles.carto-service.io/styles', 'MAP-OCH', '2027-08-31', 140, 0],
            ['Géolocalisation', 'Géolocalisation', 'Actif', 'Production', 'geo.locate-service.io/v1', 'GEO-OCH', '2027-08-31', 110, 0.3],
            ['Stockage des documents', 'Stockage', 'Actif', 'Production', 's3.af-west.storage.io/ochantier-docs', 'STO-OCH-AK', '2027-09-30', 70, 0],
            ['Signature électronique', 'Signature', 'Erreur', 'Production', 'api.esign-service.io/v3', 'SIGN-OCH-4410', '2026-09-20', 0, 12.5],
            ['Analyse documentaire (OCR)', 'Analyse documentaire', 'Test', 'Recette', 'ocr.docscan-service.io/v2', 'OCR-OCH-T', '2026-12-31', 1800, 2.2],
            ['Support client', 'Support', 'Actif', 'Production', 'helpdesk.support-service.io/api', 'HD-OCH', '2027-01-31', 330, 0],
            ['Analytics', 'Analytics', 'Suspendu', 'Production', 'collect.analytics-service.io', 'AN-OCH', '2027-01-31', 0, 0],
        ];
        foreach ($integrations as $i => [$name, $type, $status, $env, $endpoint, $client, $expires, $latency, $errors]) {
            Integration::create([
                'name' => $name, 'type' => $type, 'status' => $status, 'environment' => $env, 'endpoint' => $endpoint, 'client_id' => $client, 'secret' => 'sk_'.Str::random(24),
                'expires_on' => $expires, 'latency' => $latency, 'error_rate' => $errors, 'test_ok' => $status !== 'Erreur',
                'tested_at' => $status === 'Erreur' ? '2026-09-20 11:02' : '2026-09-2'.($i % 3).' 10:00',
                'last_result' => $status === 'Erreur' ? 'HTTP 401 · certificat expiré' : "HTTP 200 · {$latency} ms",
            ]);
        }

        $audit = [
            ['2026-09-23 08:05', 'N. Yao', 'Intégrations', 'Consultation du secret de Wave CI refusée (secret non affichable)', null, null],
            ['2026-09-22 17:44', 'A. Koné', 'Matching & affectations', 'Correspondance métier et spécialité modifié de 30 % à 25 %', '30 %', '25 %'],
            ['2026-09-21 11:02', 'Awa Bamba', 'Utilisateurs & rôles', 'Rôle de Serge Tanoh modifié de Lecture seule à Support', 'Lecture seule', 'Support'],
            ['2026-09-20 11:02', 'Système', 'Intégrations', 'Test de connexion Signature électronique en échec', null, 'HTTP 401 · certificat expiré'],
            ['2026-09-18 14:32', 'N. Yao', 'Commissions & frais', 'Commission ouvrier (Z) modifié de 8 % à 10 %', '8 %', '10 %'],
            ['2026-09-12 10:15', 'Aminata Kouassi', 'Commissions & frais', 'Seuil de paiement fixé à 50 %', '40 %', '50 %'],
            ['2026-09-08 09:12', 'M. Diabaté', 'Paiements', 'Seuil de double validation modifié de 500 000 FCFA à 1 000 000 FCFA', '500 000 FCFA', '1 000 000 FCFA'],
            ['2026-09-02 16:40', 'Awa Bamba', 'Sécurité', 'Tentatives maximales modifié de 3 essais à 5 essais', '3 essais', '5 essais'],
            ['2026-08-28 11:20', 'A. Koné', 'Métiers', 'Métiers ajoutés : Ferrailleur, Soudeur', null, null],
            ['2026-08-30 10:10', 'Awa Bamba', 'Utilisateurs & rôles', 'Compte de Yannick Gbagbo suspendu', 'Actif', 'Suspendu'],
        ];
        foreach ($audit as [$at, $author, $section, $action, $old, $new]) {
            SettingChange::create(['occurred_at' => $at, 'author' => $author, 'section' => $section, 'action' => $action, 'old_value' => $old, 'new_value' => $new]);
        }
    }
}
