<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contractor\ProposalIndexRequest;
use App\Http\Requests\V1\Contractor\QuoteProposalRequest;
use App\Http\Requests\V1\Contractor\QuoteVersionRequest;
use App\Http\Requests\V1\Contractor\SendQuoteVersionRequest;
use App\Http\Requests\V1\Contractor\VisitRescheduleRequest;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorQuoteProposals;
use App\Support\Promoter\QuoteRequests;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Propositions de devis de l’entrepreneur : envoi sur une demande de devis, « Mes offres », retrait, mission de
 * visite (visite réalisée, report proposé) et rédaction du devis en versions. Chaque action renvoie la proposition
 * à jour.
 */
class QuoteProposalController extends Controller
{
    public function __construct(private ContractorQuoteProposals $proposals, private QuoteRequests $quotes) {}

    public function store(QuoteProposalRequest $request, string $quote): JsonResponse
    {
        $bid = $this->proposals->propose(ProfileController::contractor($request), $quote, $request->validated());

        return ApiResponse::success($this->proposals->detail($bid), 'Proposition envoyée au promoteur.', status: 201);
    }

    public function index(ProposalIndexRequest $request): JsonResponse
    {
        $list = $this->proposals->list(ProfileController::contractor($request), $request->validated('tab') ?? 'en_attente', $request->validated('search'));

        return ApiResponse::success($list['data'], meta: $list['meta']);
    }

    public function show(Request $request, string $quoteProposal): JsonResponse
    {
        return ApiResponse::success($this->proposals->detail($this->proposals->find(ProfileController::contractor($request), $quoteProposal)));
    }

    public function withdraw(Request $request, string $quoteProposal): JsonResponse
    {
        $bid = $this->proposals->find(ProfileController::contractor($request), $quoteProposal);
        $this->quotes->withdraw($bid);

        return $this->fresh($request, $bid->id, 'Proposition retirée.');
    }

    public function visited(Request $request, string $quoteProposal): JsonResponse
    {
        $bid = $this->proposals->find(ProfileController::contractor($request), $quoteProposal);
        $this->quotes->markVisited($bid);

        return $this->fresh($request, $bid->id, 'Visite réalisée : vous pouvez rédiger le devis.');
    }

    public function reschedule(VisitRescheduleRequest $request, string $quoteProposal): JsonResponse
    {
        $bid = $this->proposals->find(ProfileController::contractor($request), $quoteProposal);
        $this->quotes->requestReschedule($bid, CarbonImmutable::parse($request->validated('date')), $request->validated('slot'), trim($request->validated('reason')));

        return $this->fresh($request, $bid->id, 'Report proposé au promoteur.');
    }

    public function storeVersion(Request $request, string $quoteProposal): JsonResponse
    {
        $bid = $this->proposals->find(ProfileController::contractor($request), $quoteProposal);
        $version = $this->quotes->draftVersion($bid);

        return ApiResponse::success([...$this->proposals->detail($this->proposals->find(ProfileController::contractor($request), $bid->id)), 'version_id' => $version->id],
            'Brouillon v'.$version->version.' créé.', status: 201);
    }

    public function updateVersion(QuoteVersionRequest $request, string $quoteProposal, string $version): JsonResponse
    {
        $bid = $this->proposals->find(ProfileController::contractor($request), $quoteProposal);
        $this->quotes->updateVersion($this->proposals->findVersion($bid, $version), $request->versionData());

        return $this->fresh($request, $bid->id, 'Brouillon enregistré.');
    }

    public function sendVersion(SendQuoteVersionRequest $request, string $quoteProposal, string $version): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $bid = $this->proposals->find($contractor, $quoteProposal);
        $sent = $this->quotes->sendVersion($this->proposals->findVersion($bid, $version), $contractor);

        return $this->fresh($request, $bid->id, 'Devis '.$sent->number.' v'.$sent->version.' envoyé au promoteur.');
    }

    private function fresh(Request $request, string $id, string $message): JsonResponse
    {
        return ApiResponse::success($this->proposals->detail($this->proposals->find(ProfileController::contractor($request), $id)), $message);
    }
}
