<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ActivityAuthorKind;
use App\Enums\AppProfile;
use App\Enums\PromoterPaymentStatus;
use App\Enums\PromoterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\RegisterPromoterRequest;
use App\Http\Requests\V1\RegisterWorkerRequest;
use App\Models\Accounts\PromoterAccount;
use App\Support\OneTimePassword;
use App\Support\Phone;
use App\Support\WorkerRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Création de compte depuis les applications Ouvrier et Promoteur, suivie de l’envoi du code de connexion.
 * Les commerciaux sont invités depuis le Back-office.
 */
class RegistrationController extends Controller
{
    public function __construct(public OneTimePassword $otp) {}

    public function worker(RegisterWorkerRequest $request, WorkerRegistration $registration): JsonResponse
    {
        $worker = $registration->register($request->validated(), 'Application mobile', author: null);

        $this->otp->send(AppProfile::Worker, Phone::digits($worker->phone));

        return $this->created($worker->phone, ['code' => $worker->code]);
    }

    public function promoter(RegisterPromoterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $promoter = DB::transaction(function () use ($data): PromoterAccount {
            $promoter = PromoterAccount::create([
                ...$data,
                'legal_form' => $data['legal_form'] ?? 'Particulier',
                'rccm' => $data['rccm'] ?? '—',
                'phone' => Phone::format($data['phone']),
                'slug' => PromoterAccount::uniqueSlug($data['name']),
                'status' => PromoterStatus::Pending,
                'payment_status' => PromoterPaymentStatus::Pending,
                'registered_at' => today(),
                'last_active_at' => now(),
                'demo_relations' => ['sites' => [], 'offers' => [], 'payments' => [], 'reviews' => [], 'disputes' => []],
            ]);
            $promoter->createMissingDocuments();
            $promoter->activities()->create([
                'occurred_at' => now(), 'author' => $promoter->representative, 'author_kind' => ActivityAuthorKind::Promoter,
                'action' => 'a créé son compte depuis l’Application Promoteur', 'target' => $promoter->name, 'icon' => 'person_add', 'tone' => 'b',
            ]);

            return $promoter;
        });

        $this->otp->send(AppProfile::Promoter, Phone::digits($promoter->phone));

        return $this->created($promoter->phone, ['slug' => $promoter->slug]);
    }

    /**
     * @param  array<string, string>  $account
     */
    private function created(string $phone, array $account): JsonResponse
    {
        return response()->json([
            'message' => 'Compte créé. Saisissez le code reçu par SMS pour vous connecter.',
            'phone' => Phone::mask($phone),
            'expires_in' => $this->otp->lifetime() * 60,
            'account' => $account,
        ], 201);
    }
}
