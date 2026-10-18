<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\AvailabilityType;
use App\Enums\DocumentType;
use App\Enums\NeedUrgency;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerDocumentType;
use App\Http\Controllers\Controller;
use App\Support\Catalog;
use App\Support\OneTimePassword;
use Illuminate\Http\JsonResponse;

/**
 * Listes de référence des formulaires des applications (métiers, communes, types de pièces…).
 */
class ReferenceController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $options = fn (array $cases): array => array_map(fn ($case): array => ['value' => $case->value, 'label' => $case->label()], $cases);

        return response()->json([
            'trades' => Catalog::trades(),
            'trade_details' => Catalog::TRADE_DETAILS,
            'trade_groups' => Catalog::TRADE_GROUPS,
            'communes' => Catalog::COMMUNES,
            'site_communes' => Catalog::siteCommunes(),
            'nationalities' => Catalog::NATIONALITIES,
            'qualifications' => Catalog::QUALIFICATIONS,
            'mobilities' => Catalog::MOBILITIES,
            'id_types' => Catalog::ID_TYPES,
            'acquisition_channels' => Catalog::CHANNELS,
            'site_types' => Catalog::SITE_TYPES,
            'need_types' => Catalog::NEED_TYPES,
            'experiences' => Catalog::EXPERIENCES,
            'pay_types' => Catalog::PAY_TYPES,
            'urgencies' => $options(NeedUrgency::cases()),
            'account_types' => $options(AccountType::cases()),
            'worker_availabilities' => $options([WorkerAvailability::Available, WorkerAvailability::Unavailable]),
            'period_types' => $options(AvailabilityType::cases()),
            'worker_document_types' => $options(WorkerDocumentType::cases()),
            'promoter_document_types' => $options(DocumentType::cases()),
            'otp_lifetime' => app(OneTimePassword::class)->lifetime() * 60,
        ]);
    }
}
