<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Enums\PromoterProfileKind;
use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Promoter\SiteResource;
use App\Models\Need;
use App\Models\Trade;
use App\Support\ApiResponse;
use App\Support\Promoter\PromoterAccess;
use App\Support\Promoter\PromoterNotifier;
use App\Support\Promoter\PromoterPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Accueil » : salutation, notifications non lues, chantiers en cours, métiers les plus demandés et paiements en
 * attente (pour les rôles qui voient les finances).
 */
class HomeController extends Controller
{
    /**
     * Métiers proposés tant que la plateforme n’a pas assez de demandes.
     */
    private const DEFAULT_TRADES = ['macon', 'electricien', 'plombier'];

    /**
     * Chantiers affichés dans « Mes chantiers ».
     */
    private const ACTIVE_SITES = [SiteStatus::Soumis, SiteStatus::Recherche, SiteStatus::Paiement, SiteStatus::Confirme, SiteStatus::EnCours, SiteStatus::Suspendu];

    public function __construct(private PromoterAccess $access, private PromoterNotifier $notifier, private PromoterPayments $payments) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->access->profile($user);
        $sites = $this->access->scopeSites($profile->sites(), $user)->whereIn('status', self::ACTIVE_SITES);
        $payments = $this->access->can($user, 'finance.view')
            ? $this->payments->rows($user)->whereIn('status.value', ['en_retard', 'a_payer', 'a_venir'])->values()
            : null;

        return ApiResponse::success([
            'greeting' => [
                'title' => 'BONJOUR',
                'name' => $profile->profile_kind === PromoterProfileKind::Entreprise
                    ? ($profile->user->ownedOrganizations()->value('legal_name') ?? $user->first_name)
                    : ($user->first_name ?: 'Bienvenue'),
                'subtitle' => 'Trouvez des ouvriers pour vos chantiers ici',
            ],
            'unread_notifications' => $this->notifier->unreadCount($user),
            'sites' => SiteResource::collection((clone $sites)->with(SiteController::WITH)->latest('updated_at')->limit(3)->get()),
            'sites_count' => (clone $sites)->count(),
            'top_trades' => $this->topTrades(),
            'pending_payments' => $payments ? [
                'count' => $payments->count(),
                'total' => (int) $payments->sum('amount'),
                'total_label' => $this->payments->money((int) $payments->sum('amount')),
                'items' => $payments->take(3)->values()->all(),
            ] : null,
        ]);
    }

    /**
     * Les 3 métiers les plus demandés sur la plateforme ces 90 derniers jours, complétés par les métiers usuels.
     *
     * @return list<array{id: string, code: string, label: string}>
     */
    private function topTrades(): array
    {
        $demanded = Need::query()
            ->where('created_at', '>=', now()->subDays(90))
            ->groupBy('trade_id')
            ->orderByRaw('count(*) desc')
            ->limit(3)
            ->pluck('trade_id');
        $trades = Trade::query()->whereKey($demanded)->get()->sortBy(fn (Trade $trade) => $demanded->search($trade->id))->values();

        if ($trades->count() < 3) {
            $trades = $trades->concat(Trade::query()->whereIn('code', self::DEFAULT_TRADES)->whereKeyNot($trades->modelKeys())->get()
                ->sortBy(fn (Trade $trade) => array_search($trade->code, self::DEFAULT_TRADES, true)))->take(3);
        }

        return $trades->map(fn (Trade $trade) => $trade->only(['id', 'code', 'label']))->values()->all();
    }
}
