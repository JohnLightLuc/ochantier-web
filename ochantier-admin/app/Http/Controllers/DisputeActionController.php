<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\QualityActivity;
use App\Models\QualityNotification;
use App\Models\Review;
use App\Support\QualityInsights as Q;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Actions sur les litiges : ouverture, recevabilité, affectation, priorité, procédure contradictoire (déclarations,
 * réponse, informations), preuves versionnées, notes internes, médiation, demandes transmises à Paiements,
 * Affectations et Pointages, décision motivée, exécution, recours, clôture, réouverture, annulation et archivage.
 *
 * Chaque action vérifie le rôle et l’étape du dossier, puis est tracée dans le journal du litige.
 */
class DisputeActionController extends Controller
{
    private function authorizeRight(string $right): void
    {
        abort_unless(Q::can($right), 403, Q::denied());
    }

    private function user(): string
    {
        return auth()->user()?->name ?? 'Système';
    }

    private function today(): string
    {
        return Q::today()->format('Y-m-d');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', $message);

        return back();
    }

    /**
     * Refuse une action incompatible avec l’étape du dossier.
     */
    private function guard(bool $allowed, string $message): void
    {
        if (! $allowed) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private static function fileLabel(?UploadedFile $file): ?string
    {
        return $file ? $file->getClientOriginalName().' · '.max(1, (int) round($file->getSize() / 1024)).' Ko' : null;
    }

    /**
     * Crée un litige sur une prestation (priorité automatique, accusé de réception au demandeur).
     *
     * @param  array<string, mixed>  $p  prestation
     * @param  array<string, mixed>  $data
     */
    public static function build(array $p, bool $claimantIsPromoter, array $data): Dispute
    {
        $t = Q::today()->format('Y-m-d');
        $promoter = ['name' => "{$p['author']} · {$p['promoter']}", 'role' => 'Promoteur', 'slug' => $p['promoterSlug'], 'link' => 'pro', 'phone' => ''];
        $worker = ['name' => $p['worker'], 'role' => 'Ouvrier', 'slug' => $p['workerSlug'], 'link' => 'ouv', 'phone' => ''];
        $claimant = $claimantIsPromoter ? $promoter : $worker;
        $respondent = $claimantIsPromoter ? $worker : $promoter;
        $reference = 'LIT-'.sprintf('%04d', max(130, (int) collect(Dispute::query()->pluck('reference'))->map(fn (string $r): int => (int) substr($r, 4))->max()) + 1);
        $amount = (int) ($data['amount'] ?? 0);
        $file = $data['file'] ?? null;
        $facts = $data['facts_at'] ?? $t;
        $auto = Dispute::autoPriority($data['category'], $amount, $t, Dispute::query()->where('worker_id', $p['workerId'])->exists());
        $proofs = $file ? [[
            'id' => 'pv-0', 'ref' => 'PRV-'.substr($reference, 4).'-1', 'title' => 'Pièce jointe à la demande', 'type' => 'Document', 'author' => $claimant['name'], 'role' => $claimant['role'], 'deposited_at' => $t, 'facts_at' => $facts,
            'file' => explode(' · ', $file)[0], 'size' => explode(' · ', $file)[1] ?? '—', 'visibility' => 'Parties', 'status' => 'À vérifier', 'comment' => '', 'version' => 1, 'meta' => 'Empreinte SHA-256 calculée au dépôt',
            'history' => [['v' => 1, 'at' => $t, 'by' => $claimant['name'], 'note' => 'Dépôt initial · fichier d’origine conservé']], 'views' => 0, 'duplicate_of' => '',
        ]] : [];
        $dispute = Dispute::create([
            'reference' => $reference, 'subject' => $data['subject'], 'category' => $data['category'], 'subcategory' => $data['subcategory'] ?? null, 'description' => $data['description'],
            'facts_at' => $facts, 'opened_at' => $t, 'source' => $data['source'], 'source_reference' => $data['source_reference'] ?? null, 'claimant' => $claimant, 'respondent' => $respondent,
            'site_id' => $p['siteId'], 'promoter_id' => $p['promoterId'], 'worker_id' => $p['workerId'], 'operation_reference' => $p['opRef'], 'assignment_reference' => $p['assignment'], 'prestation' => $p['prestation'], 'trade' => $p['trade'],
            'amount' => $amount, 'wish' => $data['wish'] ?? 'Mesure corrective', 'urgent' => ($data['urgent'] ?? 'Non') === 'Oui', 'priority' => $auto['level'], 'severity' => $data['severity'] ?? 'Modérée',
            'status' => $data['status'] ?? 'Soumis', 'first_response_hours' => 1, 'phase_started_at' => $t,
            'admissibility' => [
                'identity' => true, 'relation' => true, 'chantier' => true, 'aff' => true, 'delai' => CarbonImmutable::parse($facts)->diffInDays(Q::today()) <= config('admin.quality.claim_window'),
                'desc' => mb_strlen($data['description']) >= 20, 'pieces' => (bool) $file,
                'noDup' => ! Dispute::query()->where('operation_reference', $p['opRef'])->where('category', $data['category'])->whereNotIn('status', ['Clôturé', 'Annulé', 'Archivé', 'Non recevable'])->exists(), 'noFinal' => true,
            ],
            'parties' => [
                ['role' => 'Demandeur', ...$claimant, 'notified_at' => $t, 'response' => 'Déclaration déposée', 'documents' => 1 + count($proofs), 'confidentiality' => 'Standard'],
                ['role' => 'Partie mise en cause', ...$respondent, 'notified_at' => null, 'response' => 'Non notifiée (avant recevabilité)', 'documents' => 0, 'confidentiality' => 'Standard'],
                ['role' => 'Administrateur en charge', 'name' => 'Non affecté', 'slug' => null, 'link' => null, 'phone' => '', 'notified_at' => null, 'response' => '—', 'documents' => 0, 'confidentiality' => 'Interne'],
            ],
            'declarations' => [['id' => 'dc-1', 'author' => $claimant['name'], 'role' => 'Demandeur', 'date' => $t, 'type' => 'Déclaration initiale', 'content' => $data['description'], 'pieces' => array_column($proofs, 'ref'), 'visibility' => 'Parties', 'status' => 'Déposée', 'version' => 1, 'history' => []]],
            'proofs' => $proofs, 'mediation' => ['status' => 'Non proposée', 'mediator' => '', 'proposed_at' => null, 'claimant_accepts' => null, 'respondent_accepts' => null, 'calendar' => [], 'exchanges' => [], 'proposals' => [], 'agreement' => null],
            'financial_requests' => [], 'replacements' => [], 'attendance_requests' => [], 'appeals' => [], 'notes' => [],
        ]);
        $dispute->log('creation', 'a ouvert le dossier', null, $dispute->status, "Source : {$data['source']}".($dispute->source_reference ? " {$dispute->source_reference}" : '')." · priorité automatique {$auto['level']} ({$auto['why']})");
        QualityNotification::send($claimant['name'], 'SMS', "Accusé de réception du litige {$reference}", 'Aucune', $reference);

        return $dispute;
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRight('create');
        $data = $request->validate([
            'source' => ['required', Rule::in(['Réclamation', 'Avis contesté', 'Paiement', 'Pointage', 'Affectation', 'Chantier', 'Opération administrative'])], 'claimant' => ['required', Rule::in(['Promoteur de la prestation', 'Ouvrier de la prestation'])],
            'prestation' => ['required', 'string'], 'category' => ['required', Rule::in(config('admin.quality.categories'))], 'subcategory' => ['required', 'string', 'max:80'], 'subject' => ['required', 'string', 'min:8', 'max:150'],
            'description' => ['required', 'string', 'min:20'], 'facts_at' => ['required', 'date', 'before_or_equal:'.$this->today()], 'amount' => ['nullable', 'integer', 'min:0'],
            'wish' => ['required', 'string'], 'urgent' => ['required', Rule::in(['Oui', 'Non'])], 'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'], 'sincere' => ['required'], 'consent' => ['required'],
        ], ['facts_at.before_or_equal' => 'La date ne peut pas être future.']);
        $p = Q::prestations()->firstWhere('opRef', $data['prestation']) ?? throw ValidationException::withMessages(['prestation' => 'Prestation inconnue.']);
        if (Dispute::query()->where('operation_reference', $p['opRef'])->where('category', $data['category'])->whereNotIn('status', ['Clôturé', 'Annulé', 'Archivé', 'Non recevable'])->exists()) {
            throw ValidationException::withMessages(['category' => 'Doublon : un litige actif existe déjà pour cette prestation et cette catégorie.']);
        }
        $dispute = self::build($p, str_starts_with($data['claimant'], 'Promoteur'), [...$data, 'subject' => trim($data['subject']), 'description' => trim($data['description']), 'amount' => (int) ($data['amount'] ?? 0), 'file' => self::fileLabel($request->file('proof')), 'status' => 'Recevabilité en cours']);
        Inertia::flash('toast', 'Litige créé · recevabilité en cours.');

        return redirect("/avis-litiges/litiges/{$dispute->reference}");
    }

    public function bulkAssign(Request $request): RedirectResponse
    {
        $this->authorizeRight('assign');
        $data = $request->validate(['owner' => ['required', Rule::in(config('admin.quality.owners'))], 'references' => ['required', 'array', 'min:1'], 'references.*' => ['string']]);
        $list = Dispute::query()->whereIn('reference', $data['references'])->get();
        foreach ($list as $d) {
            $old = $d->owner;
            $d->owner = $data['owner'];
            $d->updateParty('Administrateur en charge', ['name' => $data['owner']]);
            $d->save();
            $d->log('affectation', 'a affecté le dossier', $old ?: 'Non affecté', $data['owner'], 'Affectation groupée');
        }
        Inertia::flash('toast', $list->count()." litige(s) affecté(s) à {$data['owner']}.");

        return redirect('/avis-litiges/litiges');
    }

    /**
     * Recevabilité : neuf contrôles ; recevable (notification des parties, réponse attendue) ou non recevable motivée.
     */
    public function qualify(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('qualif');
        $this->guard($dispute->isEarly(), 'Recevabilité déjà traitée.');
        $keys = array_keys(Dispute::ADMISSIBILITY);
        $data = $request->validate([...array_fill_keys($keys, ['required', Rule::in(['Conforme', 'Non conforme'])]), 'decision' => ['required', Rule::in(['Recevable · ouvrir la procédure contradictoire', 'Non recevable'])], 'reason' => ['required', 'string', 'min:10']]);
        $checks = collect($keys)->mapWithKeys(fn (string $k): array => [$k => $data[$k] === 'Conforme'])->all();
        $receivable = $data['decision'] !== 'Non recevable';
        if ($receivable && in_array(false, $checks, true)) {
            throw ValidationException::withMessages(['decision' => 'Tous les contrôles doivent être conformes pour déclarer le litige recevable.']);
        }
        $reason = trim($data['reason']);
        $dispute->admissibility = $checks;
        if (! $receivable) {
            $dispute->inadmissibility = $reason;
            $dispute->moveTo('Non recevable', $reason, 'controle');
            QualityNotification::send($dispute->claimant['name'], 'SMS', "Demande {$dispute->reference} non recevable : {$reason}", 'Consulter la motivation', $dispute->reference);

            return $this->done('Demande non recevable · dossier conservé.');
        }
        $rep = config('admin.quality.sla.rep');
        $dispute->qualified_at = $this->today();
        if (! $dispute->owner) {
            $dispute->owner = $this->user();
            $dispute->updateParty('Administrateur en charge', ['name' => $dispute->owner]);
        }
        $dispute->updateParty('Partie mise en cause', ['notified_at' => $this->today(), 'response' => 'Réponse attendue avant le '.Q::today()->addDays($rep)->format('d/m/Y')]);
        $dispute->moveTo('Ouvert', "Recevable · {$reason}", 'controle');
        foreach ([$dispute->claimant['name'], $dispute->respondent['name']] as $name) {
            QualityNotification::send($name, 'SMS', "Litige {$dispute->reference} ouvert · résumé disponible", $name === $dispute->respondent['name'] ? "Répondre sous {$rep} j" : 'Aucune', $dispute->reference);
        }
        $dispute->log('notification', 'a notifié les parties et communiqué le résumé', null, "Délai {$rep} j");
        $dispute->moveTo('Réponse de la partie adverse attendue', 'Délai de réponse accordé');

        return $this->done('Litige recevable · parties notifiées.');
    }

    public function assign(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('assign');
        $this->guard(! $dispute->isFinal(), 'Dossier terminé.');
        $owner = $request->validate(['owner' => ['required', Rule::in(config('admin.quality.owners'))]])['owner'];
        $old = $dispute->owner;
        $dispute->owner = $owner;
        $dispute->updateParty('Administrateur en charge', ['name' => $owner]);
        $dispute->save();
        $dispute->log('affectation', 'a affecté le dossier', $old ?: 'Non affecté', $owner);
        if ($dispute->status === 'Ouvert') {
            $dispute->moveTo('Affecté', "Responsable {$owner}");
        }

        return $this->done("Litige affecté à {$owner}.");
    }

    public function priority(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('qualif');
        $this->guard(! $dispute->isFinal(), 'Dossier terminé.');
        $data = $request->validate(['priority' => ['required', Rule::in(Dispute::PRIORITIES)], 'severity' => ['required', Rule::in(Dispute::SEVERITIES)], 'reason' => ['required', 'string', 'min:10']]);
        $old = "{$dispute->priority} / {$dispute->severity}";
        $dispute->update(['priority' => $data['priority'], 'severity' => $data['severity'], 'priority_reason' => "Ajustée par {$this->user()} : ".trim($data['reason'])]);
        $dispute->log('modification', 'a modifié la priorité et la gravité', $old, "{$data['priority']} / {$data['severity']}", trim($data['reason']));

        return $this->done('Priorité mise à jour.');
    }

    public function requestInfo(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('qualif');
        $this->guard(! $dispute->isFinal() && ! $dispute->isEarly(), 'Disponible après recevabilité.');
        $data = $request->validate(['to' => ['required', 'string'], 'question' => ['required', 'string', 'min:10']]);
        $dispute->suspension = "Informations demandées à {$data['to']} : ".trim($data['question']);
        $dispute->moveTo('Informations demandées', trim($data['question']));
        QualityNotification::send($data['to'], 'SMS', "Informations demandées sur {$dispute->reference}", 'Répondre', $dispute->reference);

        return $this->done('Demande envoyée · SLA suspendu.');
    }

    public function infoReceived(Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('qualif');
        $this->guard($dispute->status === 'Informations demandées', 'Aucune information attendue.');
        $dispute->suspension = null;
        $dispute->moveTo('Analyse en cours', 'Informations reçues · reprise du SLA');

        return $this->done('Instruction reprise.');
    }

    public function addProof(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('proof');
        $this->guard(! in_array($dispute->status, ['Clôturé', 'Archivé'], true), 'Dossier clôturé.');
        $data = $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:120'], 'type' => ['required', Rule::in(DisputeController::PROOF_TYPES)], 'by' => ['required', 'string'], 'facts_at' => ['required', 'date'],
            'visibility' => ['required', Rule::in(['Parties', 'Une seule partie', 'Interne', 'Confidentiel'])], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'], 'comment' => ['nullable', 'string'],
        ]);
        $file = $request->file('file');
        $n = count($dispute->proofs) + 1;
        $ref = 'PRV-'.substr($dispute->reference, 4)."-{$n}";
        $duplicate = collect($dispute->proofs)->firstWhere('file', $file->getClientOriginalName());
        $dispute->proofs = [...$dispute->proofs, [
            'id' => "pv-{$n}-".now()->timestamp, 'ref' => $ref, 'title' => trim($data['title']), 'type' => $data['type'], 'author' => $data['by'],
            'role' => match ($data['by']) {
                $dispute->claimant['name'] => 'Demandeur', $dispute->respondent['name'] => 'Partie mise en cause', default => 'Administration',
            },
            'deposited_at' => $this->today(), 'facts_at' => $data['facts_at'], 'file' => $file->getClientOriginalName(), 'size' => max(1, (int) round($file->getSize() / 1024)).' Ko', 'visibility' => $data['visibility'],
            'status' => 'À vérifier', 'comment' => trim((string) ($data['comment'] ?? '')), 'version' => 1, 'meta' => 'Empreinte SHA-256 '.substr(hash_file('sha256', $file->getRealPath()), 0, 12).'… · '.now()->format('H:i'),
            'history' => [['v' => 1, 'at' => $this->today(), 'by' => $data['by'], 'note' => 'Dépôt initial · fichier d’origine conservé']], 'views' => 0, 'duplicate_of' => $duplicate['ref'] ?? '',
        ]];
        $dispute->admissibility = [...$dispute->admissibility, 'pieces' => true];
        $dispute->save();
        $dispute->log('document', 'a déposé une preuve', null, $ref, trim($data['title']).($duplicate ? " · doublon potentiel de {$duplicate['ref']}" : ''));
        QualityNotification::send($dispute->owner ?: 'Gestionnaire', 'Push', "Preuve ajoutée sur {$dispute->reference}", 'Vérifier la pièce', $dispute->reference);

        return $this->done('Preuve déposée · à vérifier.');
    }

    /**
     * Met à jour un élément d’une liste JSON du dossier (preuves, déclarations…).
     *
     * @return array<string, mixed>
     */
    private function item(Dispute $dispute, string $list, string $id): array
    {
        return collect($dispute->{$list})->firstWhere('id', $id) ?? abort(404);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function updateItem(Dispute $dispute, string $list, string $id, array $changes): void
    {
        $dispute->{$list} = array_map(fn (array $x): array => $x['id'] === $id ? [...$x, ...$changes] : $x, $dispute->{$list});
        $dispute->save();
    }

    public function proofStatus(Request $request, Dispute $dispute, string $proof): RedirectResponse
    {
        $this->authorizeRight('proof');
        $p = $this->item($dispute, 'proofs', $proof);
        $data = $request->validate(['status' => ['required', Rule::in(['À vérifier', 'Vérifiée', 'Contestée', 'Incomplète', 'Illisible', 'Non pertinente', 'Rejetée', 'Archivée'])], 'by' => ['nullable', 'string'], 'reason' => ['required', 'string', 'min:10']]);
        $legal = $data['status'] === 'Archivée';
        $this->updateItem($dispute, 'proofs', $proof, [
            'status' => $data['status'], 'comment' => (! empty($data['by']) ? "Contestée par {$data['by']} : " : '').trim($data['reason']),
            ...($legal ? ['visibility' => 'Interne', 'history' => [['v' => $p['version'], 'at' => $this->today(), 'by' => $this->user(), 'note' => 'Retrait légal · trace conservée (non publique)'], ...$p['history']]] : []),
        ]);
        $dispute->log('document', 'a modifié le statut d’une preuve', $p['status'], $data['status'], "{$p['ref']} · ".trim($data['reason']));

        return $this->done("Preuve {$p['ref']} : ".mb_strtolower($data['status']).'.');
    }

    public function proofVersion(Request $request, Dispute $dispute, string $proof): RedirectResponse
    {
        $this->authorizeRight('proof');
        $p = $this->item($dispute, 'proofs', $proof);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'], 'note' => ['required', 'string', 'min:5']]);
        $version = $p['version'] + 1;
        $this->updateItem($dispute, 'proofs', $proof, ['version' => $version, 'status' => 'À vérifier', 'history' => [['v' => $version, 'at' => $this->today(), 'by' => $this->user(), 'note' => trim($data['note']).' · '.$request->file('file')->getClientOriginalName()], ...$p['history']]]);
        $dispute->log('document', 'a ajouté une version de preuve', 'v'.$p['version'], "v{$version}", $p['ref']);

        return $this->done("Version v{$version} ajoutée.");
    }

    /**
     * Consultation d’une pièce : tracée (consultation sensible pour une pièce non partagée avec les parties).
     */
    public function proofView(Dispute $dispute, string $proof): RedirectResponse
    {
        $this->authorizeRight('proof');
        $p = $this->item($dispute, 'proofs', $proof);
        abort_if($p['visibility'] === 'Confidentiel' && ! Q::can('notes'), 403, Q::denied('pièce confidentielle'));
        $this->updateItem($dispute, 'proofs', $proof, ['views' => ($p['views'] ?? 0) + 1]);
        $dispute->log('controle', 'a consulté une pièce '.mb_strtolower($p['visibility']), null, $p['ref'], 'Consultation sensible tracée');

        return $this->done("{$p['ref']} · {$p['title']} · {$p['file']} ({$p['size']}, v{$p['version']}) · consultation tracée.");
    }

    public function declare(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('lit');
        $this->guard(! $dispute->isFinal(), 'Dossier terminé.');
        $data = $request->validate(['by' => ['required', Rule::in([$dispute->claimant['name'], $dispute->respondent['name']])], 'type' => ['required', 'string'], 'content' => ['required', 'string', 'min:15'], 'visibility' => ['required', 'string'], 'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $isRespondent = $data['by'] === $dispute->respondent['name'];
        $dispute->declarations = [...$dispute->declarations, ['id' => 'dc-'.(count($dispute->declarations) + 1), 'author' => $data['by'], 'role' => $isRespondent ? 'Partie mise en cause' : 'Demandeur', 'date' => $this->today(), 'type' => $data['type'], 'content' => trim($data['content']), 'pieces' => $request->file('file') ? [$request->file('file')->getClientOriginalName()] : [], 'visibility' => $data['visibility'], 'status' => 'Déposée', 'version' => 1, 'history' => []]];
        $dispute->save();
        $dispute->log('creation', 'a enregistré une déclaration', null, $data['type'], $data['by'], $data['by'], 'Application');
        if ($isRespondent && ! $dispute->isResponded()) {
            $this->markResponded($dispute);
        }

        return $this->done('Déclaration enregistrée.');
    }

    private function markResponded(Dispute $dispute): void
    {
        $dispute->responded_at = $this->today();
        $dispute->updateParty('Partie mise en cause', ['response' => 'Réponse déposée le '.Q::today()->format('d/m/Y')]);
        if ($dispute->status === 'Réponse de la partie adverse attendue') {
            $dispute->moveTo('Analyse en cours', 'Réponse de la partie mise en cause reçue');
        } else {
            $dispute->save();
        }
    }

    public function declarationVersion(Request $request, Dispute $dispute, string $declaration): RedirectResponse
    {
        $this->authorizeRight('lit');
        $c = $this->item($dispute, 'declarations', $declaration);
        $content = trim($request->validate(['reason' => ['required', 'string', 'min:15']])['reason']);
        $this->updateItem($dispute, 'declarations', $declaration, ['history' => [['v' => $c['version'], 'at' => $c['date'], 'content' => $c['content'], 'note' => 'Version conservée'], ...$c['history']], 'version' => $c['version'] + 1, 'content' => $content, 'date' => $this->today()]);
        $dispute->log('modification', 'a déposé une nouvelle version de déclaration', "v{$c['version']}", 'v'.($c['version'] + 1), $c['author']);

        return $this->done('Version v'.($c['version'] + 1).' enregistrée.');
    }

    /**
     * Simulation d’une réponse de la partie mise en cause depuis l’application.
     */
    public function response(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('lit');
        $this->guard($dispute->status === 'Réponse de la partie adverse attendue', 'Aucune réponse attendue à cette étape.');
        $content = trim($request->validate(['reason' => ['required', 'string', 'min:15']])['reason']);
        $dispute->declarations = [...$dispute->declarations, ['id' => 'dc-'.(count($dispute->declarations) + 1), 'author' => $dispute->respondent['name'], 'role' => 'Partie mise en cause', 'date' => $this->today(), 'type' => 'Réponse', 'content' => $content, 'pieces' => [], 'visibility' => 'Parties', 'status' => 'Déposée', 'version' => 1, 'history' => []]];
        $this->markResponded($dispute);

        return $this->done('Réponse reçue · analyse en cours.');
    }

    public function note(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('notes');
        $text = trim($request->validate(['reason' => ['required', 'string', 'min:10']])['reason']);
        $dispute->update(['notes' => [['by' => $this->user(), 'date' => $this->today(), 'text' => $text], ...$dispute->notes]]);
        $dispute->log('modification', 'a ajouté une note interne', null, 'Confidentiel');

        return $this->done('Note interne ajoutée.');
    }

    /**
     * Message à une partie (avis, réclamation ou litige) via la messagerie O’CHANTIER.
     */
    public function message(Request $request): RedirectResponse
    {
        abort_unless(Q::can('lit') || Q::can('av'), 403, Q::denied());
        $data = $request->validate(['reference' => ['required', 'string'], 'to' => ['required', 'string'], 'channel' => ['required', Rule::in(['Messagerie', 'SMS', 'E-mail', 'Appel tracé'])], 'message' => ['required', 'string', 'min:10']]);
        abort_unless(Dispute::query()->where('reference', $data['reference'])->exists() || Review::query()->where('reference', $data['reference'])->exists() || Claim::query()->where('reference', $data['reference'])->exists(), 404);
        QualityNotification::send($data['to'], $data['channel'], trim($data['message']), 'Réponse attendue', $data['reference']);
        QualityActivity::record($data['reference'], 'communication', 'a contacté une partie', null, $data['to'], $data['channel']);

        return $this->done("Message envoyé à {$data['to']}.");
    }

    public function proposeMediation(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('med');
        $this->guard(! $dispute->isFinal() && ! $dispute->isEarly() && $dispute->mediation['status'] === 'Non proposée' && ! in_array($dispute->category, config('admin.quality.no_mediation'), true), 'Médiation non éligible à cette étape ou pour cette catégorie.');
        $data = $request->validate(['mediator' => ['required', Rule::in(config('admin.quality.mediators'))], 'date' => ['required', 'date'], 'place' => ['required', 'string']]);
        $dispute->mediation = [...$dispute->mediation, 'status' => 'En attente d’acceptation', 'mediator' => $data['mediator'], 'proposed_at' => $this->today(), 'claimant_accepts' => null, 'respondent_accepts' => null, 'calendar' => [['date' => $data['date'], 'time' => '10:00', 'place' => $data['place']]]];
        $dispute->parties = [...array_values(array_filter($dispute->parties, fn (array $p): bool => $p['role'] !== 'Médiateur')), ['role' => 'Médiateur', 'name' => $data['mediator'], 'slug' => null, 'link' => null, 'phone' => '', 'notified_at' => $this->today(), 'response' => 'Nommé', 'documents' => 0, 'confidentiality' => 'Tenu au secret']];
        $dispute->moveTo('Médiation proposée', $data['mediator'], 'diffusion');
        foreach ([$dispute->claimant['name'], $dispute->respondent['name']] as $name) {
            QualityNotification::send($name, 'E-mail', "Médiation proposée sur {$dispute->reference}", 'Accepter ou refuser', $dispute->reference);
        }

        return $this->done('Médiation proposée aux parties.');
    }

    /**
     * Met à jour la médiation et, le cas échéant, le statut du litige.
     *
     * @param  array<string, mixed>  $changes
     */
    private function mediation(Dispute $dispute, string $status, ?string $disputeStatus, string $comment, array $changes = []): RedirectResponse
    {
        $old = $dispute->mediation['status'];
        $dispute->mediation = [...$dispute->mediation, ...$changes, 'status' => $status];
        $dispute->save();
        $dispute->log('diffusion', 'a mis à jour la médiation', $old, $status, $comment);
        if ($disputeStatus) {
            $dispute->moveTo($disputeStatus, $comment);
        }

        return $this->done('Médiation : '.mb_strtolower($status).'.');
    }

    public function mediationAnswer(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('med');
        $this->guard(in_array($dispute->mediation['status'], ['Proposée', 'En attente d’acceptation'], true), 'Aucune proposition en attente.');
        $data = $request->validate(['who' => ['required', Rule::in([$dispute->claimant['name'], $dispute->respondent['name']])], 'answer' => ['required', Rule::in(['Acceptée', 'Refusée'])]]);
        $accepted = $data['answer'] === 'Acceptée';
        $field = $data['who'] === $dispute->claimant['name'] ? 'claimant_accepts' : 'respondent_accepts';
        $m = [...$dispute->mediation, $field => $accepted];
        if (! $accepted) {
            return $this->mediation($dispute, 'Refusée', 'Analyse en cours', "{$data['who']} a refusé la médiation", [$field => false]);
        }

        return $m['claimant_accepts'] && $m['respondent_accepts']
            ? $this->mediation($dispute, 'Acceptée', 'Médiation en cours', 'Acceptée par les deux parties', [$field => true])
            : $this->mediation($dispute, 'En attente d’acceptation', null, "{$data['who']} a accepté", [$field => true]);
    }

    private function mediationOpen(Dispute $dispute): void
    {
        $this->authorizeRight('med');
        $this->guard(in_array($dispute->mediation['status'], ['Acceptée', 'En cours', 'Accord provisoire'], true), 'Médiation non engagée.');
    }

    public function mediationExchange(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->mediationOpen($dispute);
        $text = trim($request->validate(['reason' => ['required', 'string', 'min:10']])['reason']);
        $m = $dispute->mediation;

        return $this->mediation($dispute, $m['status'] === 'Acceptée' ? 'En cours' : $m['status'], null, 'Échange ajouté', ['exchanges' => [...$m['exchanges'], ['date' => $this->today(), 'by' => $m['mediator'], 'text' => $text]]]);
    }

    public function mediationProposal(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->mediationOpen($dispute);
        $data = $request->validate(['by' => ['required', 'string'], 'text' => ['required', 'string', 'min:10']]);
        $m = $dispute->mediation;
        $proposals = array_map(fn (array $p): array => $p['status'] === 'En discussion' ? [...$p, 'status' => 'Contre-proposée'] : $p, $m['proposals']);

        return $this->mediation($dispute, $m['status'] === 'Acceptée' ? 'En cours' : $m['status'], null, "Proposition de {$data['by']}", ['proposals' => [...$proposals, ['date' => $this->today(), 'by' => $data['by'], 'text' => trim($data['text']), 'status' => 'En discussion']]]);
    }

    public function mediationAgreement(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->mediationOpen($dispute);
        $data = $request->validate([
            'claimant' => ['required', 'string', 'min:10'], 'respondent' => ['required', 'string', 'min:10'], 'amount' => ['nullable', 'integer', 'min:0'], 'replacement' => ['required', 'string'],
            'delay' => ['required', 'string'], 'payments' => ['required', 'string'], 'reviews' => ['required', 'string'], 'effective_at' => ['required', 'date'],
        ]);

        return $this->mediation($dispute, 'Accord provisoire', null, 'Accord saisi', ['agreement' => [...$data, 'amount' => (int) ($data['amount'] ?? 0), 'claimant_validated' => false, 'respondent_validated' => false]]);
    }

    /**
     * Validation de l’accord par les parties ; validé par les deux, il déclenche les demandes d’exécution.
     */
    public function mediationValidate(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('med');
        $this->guard($dispute->mediation['status'] === 'Accord provisoire', 'Aucun accord à valider.');
        $who = $request->validate(['who' => ['required', 'string']])['who'];
        $agreement = $dispute->mediation['agreement'];
        $agreement['claimant_validated'] = $agreement['claimant_validated'] || $who !== $dispute->respondent['name'];
        $agreement['respondent_validated'] = $agreement['respondent_validated'] || $who !== $dispute->claimant['name'];
        if (! ($agreement['claimant_validated'] && $agreement['respondent_validated'])) {
            return $this->mediation($dispute, 'Accord provisoire', null, "{$who} a validé", ['agreement' => $agreement]);
        }
        $types = ['Ajustement créditeur' => 'Ajustement créditeur', 'Ajustement débiteur' => 'Ajustement débiteur', 'Libération des fonds suspendus' => 'Libération d’un paiement', 'Remboursement partiel' => 'Remboursement partiel'];
        if (isset($types[$agreement['payments']]) && $agreement['amount']) {
            $dispute->financial_requests = [...$dispute->financial_requests, $this->financialRequest($dispute, $types[$agreement['payments']], $agreement['amount'], 'Exécution de l’accord de médiation', 'Accord de médiation', [$this->user()], 'Transmise à Paiements')];
        }
        if ($agreement['replacement'] !== 'Aucun') {
            $dispute->replacements = [...$dispute->replacements, $this->replacementRequest($dispute, 'Accord de médiation', 'Normale', Q::today()->addDays(5)->format('Y-m-d'), 'Pointages et documents transférés au remplaçant')];
        }

        return $this->mediation($dispute, 'Accord validé', 'Accord trouvé', 'Validé par les deux parties', ['agreement' => $agreement]);
    }

    public function mediationFail(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->mediationOpen($dispute);
        $reason = trim($request->validate(['reason' => ['required', 'string', 'min:10']])['reason']);

        return $this->mediation($dispute, 'Échec', 'Analyse en cours', $reason);
    }

    public function mediationClose(Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('med');
        $this->guard(in_array($dispute->mediation['status'], ['Accord validé', 'Échec', 'Refusée'], true), 'Médiation en cours.');

        return $this->mediation($dispute, 'Clôturée', null, 'Médiation clôturée');
    }

    /**
     * @param  array<int, string>  $approvals
     * @return array<string, mixed>
     */
    private function financialRequest(Dispute $dispute, string $type, int $amount, string $motif, string $source, array $approvals, string $status, ?string $operation = null, ?string $parent = null): array
    {
        $operation ??= $dispute->operation_reference;
        $sequence = 600 + Dispute::query()->get(['financial_requests'])->sum(fn (Dispute $d): int => count($d->financial_requests)) + 1;

        return [
            'ref' => 'DPA-'.Q::today()->year.'-'.sprintf('%04d', $sequence), 'type' => $type, 'amount' => $amount, 'motif' => $motif, 'source' => $source, 'approvals' => $approvals, 'status' => $status,
            'op_ref' => $operation, 'payment_ref' => Payment::query()->where('reference', $operation)->exists() ? $operation : null, 'at' => $this->today(), 'executed_at' => null, 'released' => false, 'parent' => $parent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replacementRequest(Dispute $dispute, string $motif, string $urgency, string $date, string $transfer): array
    {
        return [
            'ref' => 'DRA-'.Q::today()->year.'-'.sprintf('%04d', 200 + count($dispute->replacements) + $dispute->id), 'worker' => $dispute->worker?->name ?? '—', 'assignment' => $dispute->assignment_reference, 'motif' => $motif,
            'urgency' => $urgency, 'date' => $date, 'transfer' => $transfer, 'status' => 'Transmise à Affectations', 'replacement' => '', 'result' => 'En attente de proposition', 'at' => $this->today(),
        ];
    }

    /**
     * Demande vers Paiements (seconde approbation au-delà du seuil de Paramètres).
     */
    public function financial(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('fin');
        $this->guard(! $dispute->isFinal() && ! $dispute->isEarly(), 'Disponible après recevabilité.');
        $data = $request->validate(['type' => ['required', Rule::in(config('admin.quality.financial_types'))], 'amount' => ['required', 'integer', 'min:1'], 'operation' => ['required', 'string'], 'motif' => ['required', 'string', 'min:10'], 'parent' => ['nullable', 'string']]);
        $double = (int) $data['amount'] > config('admin.quality.double_approval');
        $source = ($dispute->decision['status'] ?? null) === 'Rendue' ? "Décision {$dispute->reference} v{$dispute->decision['version']}" : 'Mesure conservatoire';
        $item = $this->financialRequest($dispute, $data['type'], (int) $data['amount'], trim($data['motif']), $source, $double ? [$this->user()] : [$this->user(), 'Approbation simple'], $double ? 'En attente d’approbation' : 'Transmise à Paiements', $data['operation'], $data['parent'] ?? null);
        $dispute->update(['financial_requests' => [...$dispute->financial_requests, $item]]);
        $dispute->log('anomalie', 'a transmis une demande à Paiements', null, $item['ref'], "{$data['type']} · ".Q::money((int) $data['amount'])." · {$item['status']}");
        QualityNotification::send('Paiements', 'Interne', "{$data['type']} demandée · {$dispute->reference}", 'Exécuter la demande', $dispute->reference);

        return $this->done('Demande transmise à Paiements.');
    }

    public function replacement(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('rempl');
        $this->guard(! $dispute->isFinal() && ! $dispute->isEarly(), 'Disponible après recevabilité.');
        $data = $request->validate(['motif' => ['required', 'string'], 'urgency' => ['required', Rule::in(['Normale', 'Élevée', 'Urgente'])], 'date' => ['required', 'date'], 'transfer' => ['required', 'string']]);
        $dispute->update(['replacements' => [...$dispute->replacements, $this->replacementRequest($dispute, $data['motif'], $data['urgency'], $data['date'], $data['transfer'])]]);
        $dispute->log('affectation', 'a transmis une demande de remplacement', null, 'Affectations', $data['motif']);
        QualityNotification::send('Affectations', 'Interne', "Remplacement demandé · {$dispute->reference}", 'Proposer un remplaçant', $dispute->reference);

        return $this->done('Demande transmise à Affectations.');
    }

    public function attendance(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('ptc');
        $this->guard(! $dispute->isFinal() && ! $dispute->isEarly(), 'Disponible après recevabilité.');
        $data = $request->validate(['attendance' => ['required', 'string'], 'old' => ['required', 'string'], 'contested' => ['required', 'string'], 'requested' => ['required', 'string'], 'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $dispute->update(['attendance_requests' => [...$dispute->attendance_requests, [
            'ref' => 'DCP-'.Q::today()->year.'-'.sprintf('%04d', 100 + count($dispute->attendance_requests) + $dispute->id), 'attendance' => $data['attendance'], 'old' => $data['old'], 'contested' => $data['contested'],
            'requested' => $data['requested'], 'proof' => $request->file('proof')->getClientOriginalName(), 'decision' => 'En attente de décision', 'status' => 'Transmise à Pointages', 'at' => $this->today(),
        ]]]);
        $dispute->log('modification', 'a transmis une demande de correction de pointage', null, 'Pointages', $data['attendance']);

        return $this->done('Demande transmise à Pointages.');
    }

    /**
     * Synchronise le statut d’une demande depuis le module qui l’exécute (étape suivante du circuit).
     */
    public function syncRequest(Dispute $dispute, string $kind, string $ref): RedirectResponse
    {
        [$list, $flow, $module] = match ($kind) {
            'fin' => ['financial_requests', ['En attente d’approbation', 'Transmise à Paiements', 'En traitement', 'Exécutée'], 'Paiements'],
            'rempl' => ['replacements', ['Transmise à Affectations', 'Remplaçant proposé', 'Remplacement confirmé'], 'Affectations'],
            default => ['attendance_requests', ['Transmise à Pointages', 'En validation', 'Corrigée dans Pointages'], 'Pointages'],
        };
        $item = collect($dispute->{$list})->firstWhere('ref', $ref) ?? abort(404);
        $i = array_search($item['status'], $flow, true);
        if ($i === false || $i === count($flow) - 1) {
            return $this->done("Statut à jour dans {$module} : {$item['status']}.");
        }
        $next = $flow[$i + 1];
        $changes = ['status' => $next];
        if ($next === 'Exécutée') {
            $changes['executed_at'] = $this->today();
        }
        if ($next === 'Remplaçant proposé') {
            $changes['replacement'] = DisputeController::replacementFor($dispute);
        }
        if ($next === 'Remplacement confirmé') {
            $changes['result'] = 'Remplaçant confirmé par le promoteur';
        }
        if ($next === 'Corrigée dans Pointages') {
            $changes['decision'] = 'Correction validée dans Pointages';
        }
        $items = array_map(fn (array $x): array => $x['ref'] === $ref ? [...$x, ...$changes] : $x, $dispute->{$list});
        if ($kind === 'fin' && $next === 'Exécutée' && ($item['parent'] ?? null)) {
            $items = array_map(fn (array $x): array => $x['ref'] === $item['parent'] ? [...$x, 'released' => true] : $x, $items);
        }
        $dispute->update([$list => $items]);
        $dispute->log('controle', 'a synchronisé une demande', $item['status'], $next, $ref);

        return $this->done("Statut synchronisé : {$next}.");
    }

    /**
     * Projet de décision motivée (faits, demandes, preuves, analyse, règles, mesure, délai).
     */
    public function prepareDecision(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('dec');
        $this->guard(! $dispute->isFinal() && ! $dispute->isEarly() && ($dispute->decision['status'] ?? null) !== 'Rendue', 'Décision déjà rendue ou étape incompatible.');
        $data = $request->validate([
            'facts' => ['required', 'string', 'min:15'], 'requests' => ['required', 'string', 'min:10'], 'retained' => ['required', 'string', 'min:5'], 'rejected' => ['nullable', 'string'], 'analysis' => ['required', 'string', 'min:30'],
            'rules' => ['required', 'string'], 'result' => ['required', Rule::in(config('admin.quality.results'))], 'measure' => ['required', Rule::in(DisputeController::MEASURES)], 'delay' => ['required', 'string'],
        ]);
        $dispute->decision = [
            'status' => 'Brouillon', 'version' => $dispute->decision['version'] ?? 1, 'result' => $data['result'], 'facts' => trim($data['facts']), 'requests' => trim($data['requests']), 'proofs' => implode(', ', array_column($dispute->proofs, 'ref')),
            'retained' => trim($data['retained']), 'rejected' => trim($data['rejected'] ?? '') ?: 'Aucun', 'analysis' => trim($data['analysis']), 'rules' => trim($data['rules']),
            'measures' => $data['measure'] === 'Aucune mesure' ? [] : [$data['measure']], 'delay' => $data['delay'], 'appeal' => 'Recours possible pendant '.config('admin.quality.appeal_window').' jours après la décision',
            'author' => $this->user(), 'approver' => '', 'date' => $this->today(),
        ];
        if ($dispute->status !== 'Décision en préparation') {
            $dispute->moveTo('Décision en préparation', $data['result']);
        } else {
            $dispute->save();
        }
        $dispute->log('modification', 'a préparé le projet de décision', null, $data['result'], $data['measure']);

        return $this->done('Projet de décision enregistré.');
    }

    /**
     * Rend la décision (approbation d’un responsable distinct) ou la soumet à approbation ; transmet la mesure ordonnée.
     */
    public function renderDecision(Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('dec');
        $this->guard($dispute->decision && $dispute->decision['status'] !== 'Rendue', 'Préparez d’abord la décision.');
        $sla = config('admin.quality.sla.rep');
        $this->guard($dispute->isQualified() && ($dispute->isResponded() || $dispute->qualified_at->diffInDays(Q::today()) >= $sla), "Procédure contradictoire en cours : réponse de la partie adverse attendue (ou délai de {$sla} j non écoulé).");
        if (! Q::can('approve')) {
            $dispute->update(['decision' => [...$dispute->decision, 'status' => 'À approuver']]);
            $dispute->log('validation', 'a soumis la décision à approbation', 'Brouillon', 'À approuver', 'Approbateur : Responsable qualité & litiges');

            return $this->done('Décision soumise à l’approbation du Responsable qualité & litiges.');
        }
        $d = $dispute->decision;
        $measure = $d['measures'][0] ?? null;
        $dispute->decision = [...$d, 'status' => 'Rendue', 'approver' => config('admin.quality.approver'), 'date' => $this->today()];
        $dispute->decided_at = $this->today();
        if ($measure && in_array($measure, config('admin.quality.financial_types'), true)) {
            $dispute->financial_requests = [...$dispute->financial_requests, $this->financialRequest($dispute, $measure, $dispute->amount, 'Exécution de la décision', "Décision {$dispute->reference} v{$d['version']}", [$this->user(), 'Awa Bamba'], 'Transmise à Paiements')];
        }
        if ($measure === 'Remplacement') {
            $dispute->replacements = [...$dispute->replacements, $this->replacementRequest($dispute, 'Décision de litige', 'Élevée', Q::today()->addDays(3)->format('Y-m-d'), 'Pointages et documents transférés au remplaçant')];
        }
        if ($measure === 'Correction de pointage') {
            $dispute->attendance_requests = [...$dispute->attendance_requests, ['ref' => 'DCP-'.Q::today()->year.'-'.sprintf('%04d', 300 + count($dispute->attendance_requests) + $dispute->id), 'attendance' => $dispute->source_reference ?: 'Pointage de référence', 'old' => 'Valeur enregistrée', 'contested' => 'Valeur contestée', 'requested' => 'Correction ordonnée par la décision', 'proof' => implode(', ', array_column($dispute->proofs, 'ref')), 'decision' => 'Correction ordonnée', 'status' => 'Transmise à Pointages', 'at' => $this->today()]];
        }
        if ($measure === 'Masquer l’avis' && ($review = Review::query()->where('reference', $dispute->source_reference)->first())) {
            $old = $review->status;
            $review->update(['status' => 'Masqué temporairement', 'visibility' => 'Non public']);
            $review->log('statut', 'a masqué l’avis en exécution d’une décision', $old, 'Masqué temporairement', $dispute->reference);
        }
        $dispute->save();
        $dispute->log('validation', 'a rendu la décision', 'Décision en préparation', 'Décision rendue', "{$d['result']} · approuvée par Awa Bamba");
        foreach ([$dispute->claimant['name'], $dispute->respondent['name']] as $name) {
            QualityNotification::send($name, 'E-mail', "Décision rendue sur {$dispute->reference}", 'Consulter · recours possible '.config('admin.quality.appeal_window').' j', $dispute->reference);
        }
        $dispute->moveTo($measure ? 'En attente d’exécution' : 'Résolu', $measure ? "Mesure transmise : {$measure}" : 'Aucune mesure à exécuter', 'validation');

        return $this->done('Décision rendue et notifiée.');
    }

    /**
     * Exécution terminée : toutes les demandes doivent être exécutées dans les modules concernés.
     */
    public function executed(Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('close');
        $this->guard(in_array($dispute->status, ['En attente d’exécution', 'Accord trouvé', 'Décision rendue'], true), 'Aucune exécution en cours.');
        $open = collect($dispute->financial_requests)->reject(fn (array $f): bool => in_array($f['status'], ['Exécutée', 'Refusée'], true) || str_contains($f['status'], 'Suspendue'))->count()
            + collect($dispute->replacements)->where('status', '!=', 'Remplacement confirmé')->count()
            + collect($dispute->attendance_requests)->reject(fn (array $r): bool => str_contains($r['status'], 'Corrigée'))->count();
        $this->guard(! $open, "{$open} demande(s) non exécutée(s) : synchronisez leur statut depuis les modules concernés.");
        $dispute->moveTo('Résolu', 'Toutes les mesures exécutées', 'validation');

        return $this->done('Litige résolu.');
    }

    public function openAppeal(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('rec');
        $window = config('admin.quality.appeal_window');
        $decision = $dispute->decision;
        $this->guard(($decision['status'] ?? null) === 'Rendue', 'Aucune décision rendue.');
        $this->guard(CarbonImmutable::parse($decision['date'])->diffInDays(Q::today()) <= $window, "Délai de recours de {$window} j expiré.");
        $this->guard(! collect($dispute->appeals)->contains(fn (array $a): bool => ! in_array($a['status'], ['Clôturé', 'Non recevable', 'Décision confirmée', 'Décision modifiée', 'Décision annulée'], true)), 'Un recours est déjà en cours.');
        $data = $request->validate(['by' => ['required', Rule::in([$dispute->claimant['name'], $dispute->respondent['name']])], 'motif' => ['required', 'string'], 'description' => ['required', 'string', 'min:20'], 'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $dispute->appeals = [...$dispute->appeals, [
            'ref' => 'RCS-'.Q::today()->year.'-'.sprintf('%04d', 60 + $dispute->id + count($dispute->appeals)), 'by' => $data['by'], 'role' => $data['by'] === $dispute->claimant['name'] ? 'Demandeur' : 'Partie mise en cause',
            'motif' => $data['motif'], 'description' => trim($data['description']), 'new_elements' => $request->file('file')?->getClientOriginalName() ?? '', 'date' => $this->today(), 'status' => 'Soumis', 'outcome' => '',
        ]];
        $dispute->moveTo('Recours ouvert', $data['motif']);
        QualityNotification::send('Responsable qualité', 'E-mail', "Recours ouvert sur {$dispute->reference}", 'Examiner la recevabilité', $dispute->reference);

        return $this->done('Recours ouvert · décision initiale conservée.');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function appeal(Dispute $dispute, int $index, string $status, array $changes, string $why, ?string $disputeStatus): RedirectResponse
    {
        $appeal = $dispute->appeals[$index] ?? abort(404);
        $appeals = $dispute->appeals;
        $appeals[$index] = [...$appeal, ...$changes, 'status' => $status];
        $dispute->appeals = $appeals;
        $dispute->save();
        $dispute->log('statut', 'a traité le recours', $appeal['status'], $status, $why);
        if ($disputeStatus) {
            $dispute->moveTo($disputeStatus, $why);
        }

        return $this->done('Recours : '.mb_strtolower($status).'.');
    }

    public function appealAdmissible(Dispute $dispute, int $index): RedirectResponse
    {
        $this->authorizeRight('rec');
        $this->guard(in_array($dispute->appeals[$index]['status'] ?? '', ['Soumis', 'Recevabilité en cours'], true), 'Recevabilité déjà traitée.');
        $dispute->reopen_count++;

        return $this->appeal($dispute, $index, 'Réexamen', [], 'Recours recevable', 'Réexamen');
    }

    public function appealInadmissible(Request $request, Dispute $dispute, int $index): RedirectResponse
    {
        $this->authorizeRight('rec');
        $this->guard(in_array($dispute->appeals[$index]['status'] ?? '', ['Soumis', 'Recevabilité en cours'], true), 'Recevabilité déjà traitée.');
        $reason = trim($request->validate(['reason' => ['required', 'string', 'min:10']])['reason']);
        $pending = collect($dispute->financial_requests)->contains(fn (array $f): bool => $f['status'] !== 'Exécutée');

        return $this->appeal($dispute, $index, 'Non recevable', ['outcome' => "Non recevable : {$reason}"], $reason, $pending ? 'En attente d’exécution' : 'Résolu');
    }

    /**
     * Décision sur recours : la décision initiale est conservée ; une annulation refuse les mesures suspendues.
     */
    public function appealDecision(Request $request, Dispute $dispute, int $index): RedirectResponse
    {
        $this->authorizeRight('rec');
        $this->guard(($dispute->appeals[$index]['status'] ?? '') === 'Réexamen', 'Recours non recevable ou déjà jugé.');
        $data = $request->validate(['outcome' => ['required', Rule::in(['Décision confirmée', 'Décision modifiée', 'Décision annulée'])], 'reason' => ['required', 'string', 'min:20']]);
        $reason = trim($data['reason']);
        $dispute->appeal_decision = ['result' => $data['outcome'], 'why' => $reason, 'date' => $this->today()];
        if ($data['outcome'] !== 'Décision confirmée') {
            $dispute->financial_requests = array_map(fn (array $f): array => str_contains($f['status'], 'Suspendue') ? [...$f, 'status' => $data['outcome'] === 'Décision annulée' ? 'Refusée' : 'Transmise à Paiements'] : $f, $dispute->financial_requests);
        }
        $pending = collect($dispute->financial_requests)->contains(fn (array $f): bool => ! in_array($f['status'], ['Exécutée', 'Refusée'], true));

        return $this->appeal($dispute, $index, $data['outcome'], ['outcome' => "{$data['outcome']} · {$reason}"], $data['outcome'], $data['outcome'] === 'Décision annulée' || ! $pending ? 'Résolu' : 'En attente d’exécution');
    }

    public function close(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('close');
        $this->guard(in_array($dispute->status, ['Résolu', 'Accord trouvé', 'Non recevable'], true), 'Clôture possible après résolution, accord ou non-recevabilité.');
        $reason = trim($request->validate(['reason' => ['required', 'string', 'min:10']])['reason']);
        $dispute->moveTo('Clôturé', $reason);
        QualityNotification::send('Parties', 'SMS', "Litige {$dispute->reference} clôturé", 'Aucune', $dispute->reference);

        return $this->done('Litige clôturé.');
    }

    public function reopen(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('reopen');
        $this->guard(in_array($dispute->status, ['Clôturé', 'Résolu'], true), 'Seul un litige résolu ou clôturé peut être rouvert.');
        $reason = trim($request->validate(['reason' => ['required', 'string', 'min:15']])['reason']);
        $dispute->reopen_count++;
        $dispute->closed_at = null;
        $dispute->moveTo('Analyse en cours', "Réouverture n° {$dispute->reopen_count} · {$reason}");

        return $this->done('Litige rouvert.');
    }

    public function cancel(Request $request, Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('close');
        $this->guard(! $dispute->isFinal(), 'Dossier déjà terminé.');
        $dispute->moveTo('Annulé', trim($request->validate(['reason' => ['required', 'string', 'min:10']])['reason']));

        return $this->done('Litige annulé.');
    }

    public function archive(Dispute $dispute): RedirectResponse
    {
        $this->authorizeRight('close');
        $this->guard(in_array($dispute->status, ['Clôturé', 'Non recevable', 'Annulé'], true), 'Archivage possible après clôture, non-recevabilité ou annulation.');
        $dispute->moveTo('Archivé', 'Archivage');

        return $this->done('Litige archivé.');
    }
}
