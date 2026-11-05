import { stack, RelationTable } from '@/components/promoters/RelationTabs';
import { StackCell, TextCell } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import FormDialog from '@/components/overlays/FormDialog';
import { Block } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber } from '@/lib/format';
import type { OfferRow } from '@/types/offers';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Criterion = { key: string; label: string; weight: number; value: number; contribution: number; detail: string };

type Suggestion = {
    id: string;
    rank: number | null;
    score: number;
    level: { label: string; tone: string };
    requested: boolean;
    status: { value: string; label: string; tone: string };
    workerId: string;
    name: string;
    code: string;
    phone: string | null;
    contact: { channel: string; at: string | null } | null;
    response: { decision: { value: string; label: string }; conditions: string | null; net_daily_rate: number | null; at: string | null } | null;
    commune: string | null;
    rating: number | null;
    experience: number | null;
    distance: number | null;
    rate: number | null;
    promoterRate: number | null;
    criteria: Criterion[];
    warnings: string[];
};

type Need = {
    id: string;
    reference: string;
    trade: string | null;
    specialty: string | null;
    headcount: number;
    experience: number | null;
    budget: number | null;
    status: string;
    lastRun: { at: string; by: string | null; stale: boolean } | null;
    suggestions: Suggestion[];
    exclusions: {
        id: string;
        workerId: string;
        name: string;
        code: string;
        nature: string;
        absolute: boolean;
        reason: string | null;
        canRequest: boolean;
        canLift: boolean;
        derogation: { id: string; state: string; justification: string; requestedBy: string | null; approvedBy: string | null; canDecide: boolean } | null;
    }[];
};

type Props = { offer: OfferRow; runRefusal: string | null; retained: number; needs: Need[]; can: { run: boolean; update: boolean; approve: boolean } };

const CHANNELS = [
    { value: 'appel', label: 'Appel' },
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'sms', label: 'SMS' },
    { value: 'visite', label: 'Visite' },
];

/** Un ouvrier ne se traite plus une fois proposé. */
const OPEN = ['suggere', 'contacte', 'accepte', 'refuse', 'indisponible'];

const fcfa = (n: number | null) => (n != null ? `${formatNumber(n)} FCFA` : '—');
const decimal = (n: number) => String(n).replace('.', ',');

/** Résultats du matching d’un besoin : classement expliqué par métier, et ouvriers exclus. */
export default function MatchingOffer({ offer: o, runRefusal, retained, needs, can }: Props) {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);
    const hasRun = needs.some((n) => n.lastRun);
    const required = needs.reduce((sum, n) => sum + n.headcount, 0);

    const contact = (s: Suggestion) => setDialog(<FormDialog title={`Contact de ${s.name}`} body={`${s.phone ?? 'Numéro inconnu'} · en attente de sa réponse.`} icon="call" okLabel="Enregistrer" url={`/matching/suggestions/${s.id}/contact`} onClose={close} fields={[{ name: 'channel', label: 'Canal', type: 'select', required: true, full: true, options: CHANNELS }]} />);

    const respond = (s: Suggestion) =>
        setDialog(
            <FormDialog
                title={`Réponse de ${s.name}`}
                body={`${s.phone ?? 'Numéro inconnu'} · tarif net annoncé : ${fcfa(s.rate)} par jour. La date, l’auteur et le tarif sont enregistrés.`}
                icon="how_to_reg"
                okLabel="Enregistrer la réponse"
                url={`/matching/suggestions/${s.id}/reponse`}
                onClose={close}
                fields={[
                    { name: 'decision', label: 'Réponse', type: 'select', required: true, options: [{ value: 'accepte', label: 'D’accord' }, { value: 'refuse', label: 'Refuse' }, { value: 'indisponible', label: 'Indisponible' }] },
                    { name: 'channel', label: 'Canal', type: 'select', required: true, options: CHANNELS },
                    { name: 'conditions', label: 'Conditions ou commentaire', type: 'textarea' },
                ]}
            />,
        );

    const exclude = (s: Suggestion) =>
        setDialog(
            <FormDialog
                title={`Écarter ${s.name} de ce besoin ?`}
                body="L’ouvrier ne sera plus proposé sur ce besoin. Vous pourrez annuler cet écart."
                icon="person_remove"
                tone="danger"
                okLabel="Écarter"
                url={`/matching/suggestions/${s.id}/ecart`}
                onClose={close}
                fields={[
                    { name: 'category', label: 'Motif', type: 'select', required: true, full: true, options: ['Mauvais retour', 'Demande du promoteur', 'Doute sur le dossier', 'Autre'] },
                    { name: 'reason', label: 'Précisions', type: 'textarea', required: true },
                ]}
            />,
        );

    const askDerogation = (e: Need['exclusions'][number]) =>
        setDialog(<FormDialog title={`Demander une dérogation pour ${e.name}`} body={`${e.reason ?? ''} Une autre personne devra l’approuver, puis il faudra relancer le matching.`} icon="gavel" tone="warning" okLabel="Demander" url={`/matching/exclusions/${e.id}/derogation`} onClose={close} fields={[{ name: 'justification', label: 'Justification', type: 'textarea', required: true }]} />);

    const send = () =>
        ui.confirm({
            icon: 'send',
            title: 'Envoyer la proposition au promoteur ?',
            body: `${retained} ouvrier(s) d’accord sur ${required} poste(s). L’offre sera publiée et le promoteur notifié.${retained < required ? ' La proposition sera partielle.' : ''}`,
            ok: 'Envoyer',
            onConfirm: () => router.post(`/matching/besoins/${o.id}/envoi`, {}, { preserveScroll: true }),
        });

    const explain = (s: Suggestion) =>
        ui.openPanel({
            kicker: `Détail du score · ${s.code}`,
            title: `${s.name} · ${decimal(s.score)} / 100`,
            badges: [
                { label: s.level.label, tone: s.level.tone as never },
                ...(s.requested ? [{ label: 'Demandé par le promoteur', tone: 'p' as never }] : []),
            ],
            text: s.warnings.length ? `À vérifier : ${s.warnings.join(' · ')}.` : 'Aucun point d’attention.',
            listTitle: 'Contribution des critères',
            list: s.criteria.map((c) => ({ icon: 'analytics', tone: 'b', name: c.label, sub: `${c.detail} · ${c.value} / 100 · pondération ${c.weight} %`, badge: `+${decimal(c.contribution)} pts`, badgeTone: 'g' })),
            actions: [{ label: 'Voir la fiche', icon: 'open_in_new', variant: 'primary', onClick: () => router.visit(`/ouvriers/${s.workerId}`) }],
        });

    return (
        <>
            <Head title={`Matching · ${o.reference}`} />
            <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-3)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                <div style={{ flex: '1 1 320px', minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-3xl)', fontWeight: 'var(--fw-bold)' }}>{o.title}</h2>
                        <StatusBadge label={o.status.label} tone={o.status.tone} />
                    </div>
                    <div style={{ marginTop: 'var(--space-1)', color: 'var(--c-ink-2)' }}>
                        {o.site.label} · {o.promoter.name} · {o.reference}
                        {o.startsOn ? ` · démarrage le ${formatDate(o.startsOn)}` : ''}
                    </div>
                    {runRefusal && <div style={{ marginTop: 'var(--space-1_5)', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{runRefusal}</div>}
                </div>
                <Button label="Voir le besoin" icon="assignment" variant="secondary" onClick={() => router.visit(`/besoins/${o.id}`)} />
                {can.update && !runRefusal && retained > 0 && <Button label={`Envoyer au promoteur (${retained})`} icon="send" variant="primary" onClick={send} />}
                {can.run && !runRefusal && <Button label={hasRun ? 'Relancer le matching' : 'Lancer le matching'} icon="join_inner" variant="secondary" onClick={() => router.post(`/matching/besoins/${o.id}/lancement`, {}, { preserveScroll: true })} />}
            </div>

            <div style={stack}>
                {needs.map((n) => (
                    <Block key={n.id} icon="engineering" title={`${n.trade ?? '—'} · ${n.headcount} poste(s)`} count={n.lastRun ? `Calcul du ${formatDate(n.lastRun.at)}${n.lastRun.by ? ` par ${n.lastRun.by}` : ''}${n.lastRun.stale ? ' · à relancer' : ''}` : 'Pas encore calculé'}>
                        <div style={stack}>
                            <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                                {[n.specialty, n.experience != null ? `${n.experience} ans d’expérience minimum` : null, n.budget ? `Budget ${fcfa(n.budget)} / jour` : null, n.status].filter(Boolean).join(' · ')}
                            </div>
                            <RelationTable
                                columns={['Rang', 'Ouvrier', 'Score', 'Niveau', 'Distance', 'Tarif net', 'Tarif promoteur', 'Statut']}
                                empty={{ icon: 'search_off', title: n.lastRun ? 'Aucun ouvrier proposable' : 'Pas encore de résultat', text: n.lastRun ? 'Consultez les exclusions ci-dessous.' : 'Lancez le matching pour classer les ouvriers.' }}
                                rows={n.suggestions.map((s) => ({
                                    key: s.id,
                                    cells: [
                                        <TextCell strong>{s.rank ?? '—'}</TextCell>,
                                        <StackCell main={s.name} sub={[s.code, s.phone, s.commune].filter(Boolean).join(' · ')} strong />,
                                        <TextCell strong>{decimal(s.score)}</TextCell>,
                                        <StatusBadge label={s.level.label} tone={s.level.tone as never} />,
                                        <TextCell muted={s.distance == null}>{s.distance != null ? `${decimal(s.distance)} km` : '—'}</TextCell>,
                                        <TextCell>{fcfa(s.rate)}</TextCell>,
                                        <TextCell>{fcfa(s.promoterRate)}</TextCell>,
                                        <StatusBadge label={s.requested ? `${s.status.label} · demandé` : s.status.label} tone={s.status.tone as never} />,
                                    ],
                                    actions: [
                                        ...(can.update && !runRefusal && OPEN.includes(s.status.value)
                                            ? [
                                                  ...(s.status.value === 'suggere' ? [{ icon: 'call', label: 'Noter le contact', onClick: () => contact(s) }] : []),
                                                  { icon: 'how_to_reg', label: 'Enregistrer la réponse', onClick: () => respond(s) },
                                                  ...(['suggere', 'contacte', 'accepte'].includes(s.status.value) ? [{ icon: 'person_remove', label: 'Écarter', onClick: () => exclude(s) }] : []),
                                              ]
                                            : []),
                                        { icon: 'analytics', label: 'Détail du score', onClick: () => explain(s) },
                                        { icon: 'visibility', label: 'Voir la fiche', onClick: () => router.visit(`/ouvriers/${s.workerId}`) },
                                    ],
                                }))}
                            />
                            {n.exclusions.length > 0 && (
                                <details>
                                    <summary style={{ cursor: 'pointer', fontWeight: 'var(--fw-semibold)' }}>{n.exclusions.length} ouvrier(s) exclu(s)</summary>
                                    <div style={{ marginTop: 'var(--space-2)' }}>
                                        <RelationTable
                                            columns={['Ouvrier', 'Nature', 'Motif']}
                                            empty={{ icon: 'block', title: '', text: '' }}
                                            rows={n.exclusions.map((e) => ({
                                                key: e.id,
                                                cells: [<StackCell main={e.name} sub={e.code} strong />, <StatusBadge label={e.nature} tone={e.absolute ? 'r' : 'a'} />, <TextCell>{e.reason ?? '—'}</TextCell>, e.derogation ? <StackCell main={e.derogation.state === 'approuvee' ? 'Approuvée' : 'En attente'} sub={e.derogation.state === 'approuvee' ? `Par ${e.derogation.approvedBy}` : `Demandée par ${e.derogation.requestedBy}`} /> : <TextCell muted>—</TextCell>],
                                                actions: [
                                                    ...(can.update && e.canRequest ? [{ icon: 'gavel', label: 'Demander une dérogation', onClick: () => askDerogation(e) }] : []),
                                                    ...(can.update && e.canLift ? [{ icon: 'undo', label: 'Annuler l’écart', onClick: () => router.delete(`/matching/exclusions/${e.id}`, { preserveScroll: true }) }] : []),
                                                    ...(can.approve && e.derogation?.canDecide ? [{ icon: 'check_circle', label: 'Approuver la dérogation', onClick: () => router.post(`/matching/derogations/${e.derogation?.id}/approbation`, {}, { preserveScroll: true }) }] : []),
                                                    ...(can.approve && e.derogation ? [{ icon: 'cancel', label: e.derogation.state === 'approuvee' ? 'Retirer la dérogation' : 'Refuser la dérogation', onClick: () => router.delete(`/matching/derogations/${e.derogation?.id}`, { preserveScroll: true }) }] : []),
                                                    { icon: 'visibility', label: 'Voir la fiche', onClick: () => router.visit(`/ouvriers/${e.workerId}`) },
                                                ],
                                            }))}
                                        />
                                    </div>
                                </details>
                            )}
                        </div>
                    </Block>
                ))}
            </div>

            {dialog}
        </>
    );
}
