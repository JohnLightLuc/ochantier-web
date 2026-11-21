<?php

namespace App\Http\Controllers\Api\V1\Admin\Landing;

use App\Enums\LandingApplicationStatus;
use App\Enums\LandingMessageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Models\LandingContactMessage;
use App\Models\LandingWorkerApplication;
use App\Models\NewsletterSubscriber;
use App\Support\Admin\LandingLeads;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Demandes du site public dans le Back Office : candidatures « Devenir ouvrier », messages de contact et inscrits à
 * la lettre d’information.
 */
class LandingLeadController extends Controller
{
    public function __construct(private LandingLeads $leads) {}

    /**
     * Compteurs de la vue d’ensemble de la Landing Page.
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success(array_filter([
            'applications' => $user->hasPermission('workers.read') ? $this->leads->applicationCounts() : null,
            'messages' => $user->hasPermission('tickets.read') ? [...$this->leads->messageCounts(), 'response_hours' => $this->leads->responseHours()] : null,
            'newsletter' => $user->hasPermission('content.read') ? ['active' => NewsletterSubscriber::query()->whereNull('unsubscribed_at')->count(), 'month' => NewsletterSubscriber::query()->whereNull('unsubscribed_at')->where('subscribed_at', '>=', now()->startOfMonth())->count()] : null,
        ], fn ($part) => $part !== null));
    }

    public function applications(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(LandingApplicationStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'trade' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $rows = $this->leads->applications($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(collect($rows->items())->map(fn (LandingWorkerApplication $a) => $this->applicationRow($a))->all(), meta: [
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(),
            'counts' => $this->leads->applicationCounts(),
            'trades' => LandingWorkerApplication::query()->distinct()->orderBy('trade_label')->pluck('trade_label'),
        ]);
    }

    public function application(Request $request, string $application): JsonResponse
    {
        return $this->applicationFiche($request, $application);
    }

    public function review(Request $request, string $application): JsonResponse
    {
        $this->leads->review($this->findApplication($application), $request->user(), $request);

        return $this->applicationFiche($request, $application, 'Candidature prise en charge.');
    }

    public function refuse(PromoterReasonRequest $request, string $application): JsonResponse
    {
        $this->leads->refuse($this->findApplication($application), $request->reason(), $request->user(), $request);

        return $this->applicationFiche($request, $application, 'Candidature refusée.');
    }

    public function messages(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(LandingMessageStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'late' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $rows = $this->leads->messages($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(collect($rows->items())->map(fn (LandingContactMessage $m) => $this->messageRow($m))->all(), meta: [
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(),
            'counts' => $this->leads->messageCounts(),
            'response_hours' => $this->leads->responseHours(),
        ]);
    }

    public function message(string $message): JsonResponse
    {
        return ApiResponse::success($this->messageFiche($message));
    }

    public function handle(Request $request, string $message): JsonResponse
    {
        $data = $request->validate(
            ['status' => ['required', 'in:en_cours,traite'], 'note' => ['nullable', 'string', 'max:2000']],
            ['status.required' => 'Choisissez le nouveau statut.', 'status.in' => 'Statut inconnu.'],
        );
        $status = LandingMessageStatus::from($data['status']);
        $this->leads->handle($this->findMessage($message), $status, isset($data['note']) ? trim($data['note']) : null, $request->user(), $request);

        return ApiResponse::success($this->messageFiche($message), $status === LandingMessageStatus::Traite ? 'Message traité.' : 'Message pris en charge.');
    }

    public function newsletter(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $rows = NewsletterSubscriber::query()->whereNull('unsubscribed_at')
            ->when($search !== '', fn ($query) => $query->where('email', 'ilike', '%'.$search.'%'))
            ->latest('subscribed_at')->paginate(min(100, max(1, $request->integer('per_page', 50))));

        return ApiResponse::success(collect($rows->items())->map(fn (NewsletterSubscriber $s) => ['id' => $s->id, 'email' => $s->email, 'subscribed_at' => $s->subscribed_at?->toIso8601String()])->all(), meta: [
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(),
        ]);
    }

    public function exportNewsletter(): StreamedResponse
    {
        $rows = NewsletterSubscriber::query()->whereNull('unsubscribed_at')->orderBy('subscribed_at')->get(['email', 'subscribed_at']);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['E-mail', 'Inscrit le'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row->email, $row->subscribed_at?->format('d/m/Y')], ';');
            }

            fclose($out);
        }, 'newsletter-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function findApplication(string $id): LandingWorkerApplication
    {
        abort_unless(str($id)->isUuid(), 404);

        return LandingWorkerApplication::query()->with(['trade', 'commune', 'worker', 'identityMedia', 'photoMedia', 'handledByAdmin'])->findOrFail($id);
    }

    private function findMessage(string $id): LandingContactMessage
    {
        abort_unless(str($id)->isUuid(), 404);

        return LandingContactMessage::query()->with('handledByAdmin')->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationRow(LandingWorkerApplication $a): array
    {
        return [
            'id' => $a->id,
            'reference' => $a->reference,
            'full_name' => trim($a->first_name.' '.$a->last_name),
            'phone' => $a->phone,
            'trade' => ['label' => $a->trade_label, 'matched' => $a->trade?->only(['id', 'label'])],
            'commune' => ['label' => $a->commune_label, 'matched' => $a->commune?->only(['id', 'name'])],
            'experience_years' => $a->experience_years,
            'level' => $a->level,
            'availability' => $a->availability,
            'target_site' => $a->target_site,
            'status' => ['value' => $a->status->value, 'label' => $a->status->label()],
            'duplicates_count' => (int) ($a->duplicates_count ?? 0),
            'worker' => $a->worker?->only(['id', 'reference']),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    private function applicationFiche(Request $request, string $id, ?string $message = null): JsonResponse
    {
        $a = self::findApplication($id);
        $open = in_array($a->status, [LandingApplicationStatus::Nouvelle, LandingApplicationStatus::EnVerification], true);

        return ApiResponse::success([
            ...$this->applicationRow($a),
            'first_name' => $a->first_name,
            'last_name' => $a->last_name,
            'email' => $a->email,
            'birth_date' => $a->birth_date?->toDateString(),
            'gender' => $a->gender,
            'daily_rate' => $a->daily_rate,
            'skills' => $a->skills,
            'certifications' => $a->certifications,
            'bio' => $a->bio,
            'neighborhood' => $a->neighborhood,
            'mobility' => $a->mobility,
            'available_days' => $a->available_days,
            'payout' => ['method' => $a->payout_method, 'number' => $a->payout_number],
            'target_site_commune' => $a->target_site_commune,
            'refusal_reason' => $a->refusal_reason,
            'handled_by' => $a->handledByAdmin?->fullName(),
            'files' => $this->leads->files($a),
            'duplicates' => $this->leads->duplicates($a),
            'prefill' => $this->leads->prefill($a),
            'actions' => [
                'review' => $a->status === LandingApplicationStatus::Nouvelle,
                'refuse' => $open,
                'convert' => $open && $request->user()->hasPermission('workers.create'),
            ],
        ], $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function messageRow(LandingContactMessage $m): array
    {
        return [
            'id' => $m->id,
            'reference' => $m->reference,
            'profile' => $m->profile,
            'name' => $m->name,
            'company' => $m->company,
            'subject' => $m->subject,
            'status' => ['value' => $m->status->value, 'label' => $m->status->label()],
            'late' => $this->leads->isLate($m),
            'handled_by' => $m->handledByAdmin?->fullName(),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messageFiche(string $id): array
    {
        $m = $this->findMessage($id);

        return [
            ...$this->messageRow($m),
            'phone' => $m->phone,
            'email' => $m->email,
            'message' => $m->message,
            'handling_note' => $m->handling_note,
            'handled_at' => $m->handled_at?->toIso8601String(),
            'due_at' => $m->created_at?->addHours($this->leads->responseHours())->toIso8601String(),
            'actions' => ['start' => $m->status === LandingMessageStatus::Nouveau, 'close' => $m->status !== LandingMessageStatus::Traite],
        ];
    }
}
