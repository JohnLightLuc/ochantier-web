<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\WorkerActivityCategory;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerDocumentType;
use App\Enums\WorkerStatus;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;

/**
 * Création d’un profil ouvrier depuis une application mobile, avec les mêmes valeurs par défaut que le
 * formulaire du Back-office (matricule, pièces manquantes, journal).
 */
class WorkerRegistration
{
    /**
     * @param  array<string, mixed>  $data  Champs validés (RegisterWorkerRequest).
     * @param  string|null  $author  Auteur du journal ; par défaut l’ouvrier lui-même.
     */
    public function register(array $data, string $channel, ?string $recruiter = null, ?string $salesZone = null, ?string $author = null, string $origin = 'Application Ouvrier'): Worker
    {
        return DB::transaction(function () use ($data, $channel, $recruiter, $salesZone, $author, $origin): Worker {
            $name = "{$data['first_name']} {$data['last_name']}";

            $worker = Worker::create([
                ...$data,
                'phone' => Phone::format($data['phone']),
                'slug' => Worker::uniqueSlug($name),
                'code' => Worker::nextCode(),
                'city' => 'Abidjan',
                'country' => 'Côte d’Ivoire',
                'secondary_trades' => $data['secondary_trades'] ?? [],
                'specialties' => [],
                'skills' => [],
                'certifications' => [],
                'zones' => [$data['commune']],
                'experience_years' => $data['experience_years'] ?? 0,
                'daily_rate' => $data['daily_rate'] ?? 0,
                'recruiter' => $recruiter,
                'sales_zone' => $salesZone,
                'acquisition_channel' => $channel,
                'status' => $recruiter ? WorkerStatus::ToReview : WorkerStatus::Incomplete,
                'availability' => WorkerAvailability::Available,
                'registered_at' => today(),
                'demo_relations' => ['assignments' => [], 'timesheets' => [], 'payouts' => [], 'reviews' => [], 'replacements' => [], 'stats' => ['missions' => 0, 'days' => 0, 'presence' => 0, 'acceptance' => 0, 'cancellation' => 0]],
            ]);

            foreach (WorkerDocumentType::cases() as $type) {
                $worker->documents()->create(['type' => $type, 'status' => DocumentStatus::Missing]);
            }

            $worker->logActivity(WorkerActivityCategory::Creation, 'a créé le profil', null, $worker->status->label(), $channel, $origin, $author ?? $name);

            return $worker;
        });
    }
}
