import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import { StackCell, TextCell } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { formatDate, formatNumber } from '@/lib/format';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Candidate = {
    id: string;
    rank: number;
    score: number;
    status: { value: string; label: string; tone: string };
    workerId: string;
    name: string;
    code: string;
    phone: string | null;
    commune: string | null;
    distance: number | null;
    rate: number | null;
    warnings: string[];
};

type Search = {
    id: string;
    refusal: string | null;
    canRun: boolean;
    canSend: boolean;
    promoterRefusal: string | null;
    ceiling: number | null;
    fromDate: string | null;
    lastRun: { at: string | null; by: string | null; excluded: number } | null;
    suggestions: Candidate[];
};

type Assignment = {
    id: string;
    status: { value: string; label: string; tone: string };
    worker: { id: string; reference: string; full_name: string; phone: string | null };
    site: { id: string; reference: string; label: string; commune: string | null };
    promoter: { name: string };
    mission: { reference: string };
    offerId: string;
    trade: string | null;
    role: string | null;
    startsOn: string | null;
    endsOn: string | null;
    promoterRate: number | null;
    netRate: number | null;
    replacement: { id: string; status: { label: string } } | null;
    actualStart: string | null;
    actualEnd: string | null;
    endReason: string | null;
    replaces: { id: string; worker: string } | null;
    days: { validated: number; pending: number; absent: number };
    unavailabilities: { id: string; status: string; declaredBy: string; reason: string | null; comment: string | null; date: string | null }[];
    replacements: { id: string; status: string; replacedBy: { id: string; full_name: string } | null; date: string | null }[];
    search: Search | null;
    can: { declare_unavailability: boolean };
};

type Props = { assignment: Assignment; reasons: Option[]; can: { update: boolean; export: boolean; contact: boolean } };

const CHANNELS = [
    { value: 'appel', label: 'Appel' },
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'sms', label: 'SMS' },
    { value: 'visite', label: 'Visite' },
];

/** Un candidat ne se traite plus une fois envoyé au promoteur. */
const OPEN = ['suggere', 'contacte', 'accepte', 'refuse', 'indisponible'];

const day = (value: string | null) => (value ? formatDate(value) : '—');
const fcfa = (n: number | null) => (n != null ? `${formatNumber(n)} FCFA` : '—');

/** Fiche d’une affectation : période, tarifs figés, jours pointés, indisponibilités et remplacements. */
export default function AssignmentShow({ assignment: a, reasons, can }: Props) {
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);

    const declare = () =>
        setDialog(
            <FormDialog
                title={`Déclarer ${a.worker.full_name} indisponible ?`}
                body="Un remplacement sera ouvert. L’ouvrier reste affecté jusqu’à ce que le promoteur choisisse un remplaçant."
                icon="event_busy"
                tone="warning"
                okLabel="Déclarer"
                url={`/affectations/${a.id}/indisponibilite`}
                onClose={close}
                fields={[
                    { name: 'reason', label: 'Motif', type: 'select', required: true, full: true, options: reasons },
                    { name: 'comment', label: 'Commentaire', type: 'textarea' },
                ]}
            />,
        );

    const contact = (c: Candidate) => setDialog(<FormDialog title={`Contact de ${c.name}`} body={`${c.phone ?? 'Numéro inconnu'} · en attente de sa réponse.`} icon="call" okLabel="Enregistrer" url={`/matching/suggestions/${c.id}/contact`} onClose={close} fields={[{ name: 'channel', label: 'Canal', type: 'select', required: true, full: true, options: CHANNELS }]} />);

    const respond = (c: Candidate) =>
        setDialog(
            <FormDialog
                title={`Réponse de ${c.name}`}
                body={`${c.phone ?? 'Numéro inconnu'} · tarif net annoncé : ${fcfa(c.rate)} par jour. La date, l’auteur et le tarif sont enregistrés.`}
                icon="how_to_reg"
                okLabel="Enregistrer la réponse"
                url={`/matching/suggestions/${c.id}/reponse`}
                onClose={close}
                fields={[
                    { name: 'decision', label: 'Réponse', type: 'select', required: true, options: [{ value: 'accepte', label: 'D’accord' }, { value: 'refuse', label: 'Refuse' }, { value: 'indisponible', label: 'Indisponible' }] },
                    { name: 'channel', label: 'Canal', type: 'select', required: true, options: CHANNELS },
                    { name: 'conditions', label: 'Conditions ou commentaire', type: 'textarea' },
                ]}
            />,
        );

    const search = a.search;
    const post = (url: string) => router.post(url, {}, { preserveScroll: true });

    return (
        <>
            <Head title={`Affectation · ${a.worker.full_name}`} />
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <section style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-3)', alignItems: 'center', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                    <div style={{ flex: '1 1 320px', minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                            <h2 style={{ margin: 0, fontSize: 'var(--fs-3xl)', fontWeight: 'var(--fw-bold)' }}>{a.worker.full_name}</h2>
                            <StatusBadge label={a.status.label} tone={a.status.tone as never} />
                            {a.replacement && <StatusBadge label={`Remplacement · ${a.replacement.status.label}`} tone="a" />}
                        </div>
                        <div style={{ marginTop: 'var(--space-1)', color: 'var(--c-ink-2)' }}>
                            {a.site.label} · {a.promoter.name} · {a.mission.reference}
                        </div>
                    </div>
                    <Button label="Fiche de l’ouvrier" icon="engineering" variant="secondary" onClick={() => router.visit(`/ouvriers/${a.worker.id}`)} />
                    <Button label="Chantier" icon="construction" variant="secondary" onClick={() => router.visit(`/chantiers/${a.site.id}`)} />
                    {can.update && a.can.declare_unavailability && <Button label="Déclarer une indisponibilité" icon="event_busy" variant="primary" onClick={declare} />}
                </section>

                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                    <StatCard label="Journées validées" value={String(a.days.validated)} icon="verified" tone="g" />
                    <StatCard label="À valider" value={String(a.days.pending)} icon="pending_actions" tone="a" />
                    <StatCard label="Absences" value={String(a.days.absent)} icon="person_off" tone="a" />
                </div>

                {search && (
                    <section style={{ ...stack, background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 }}>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-3)', alignItems: 'center' }}>
                            <div style={{ flex: '1 1 320px', minWidth: 0 }}>
                                <h3 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)' }}>Recherche d’un remplaçant</h3>
                                <div style={{ marginTop: 'var(--space-1)', color: 'var(--c-ink-2)' }}>
                                    {search.refusal ?? `Relève le ${day(search.fromDate)}, tarif promoteur figé à ${fcfa(search.ceiling)}.`}
                                    {search.lastRun && ` Dernier calcul le ${day(search.lastRun.at)}${search.lastRun.by ? ` par ${search.lastRun.by}` : ''}, ${search.lastRun.excluded} ouvrier(s) exclu(s).`}
                                </div>
                                {search.promoterRefusal && <div style={{ marginTop: 'var(--space-1)', color: 'var(--c-danger, var(--c-ink-2))' }}>Refus du promoteur : {search.promoterRefusal}</div>}
                            </div>
                            {can.update && search.canRun && <Button label={search.lastRun ? 'Relancer la recherche' : 'Lancer la recherche'} icon="join_inner" variant="secondary" onClick={() => post(`/affectations/remplacements/${search.id}/recherche`)} />}
                            {can.update && search.canSend && <Button label="Envoyer au promoteur" icon="send" variant="primary" onClick={() => post(`/affectations/remplacements/${search.id}/envoi`)} />}
                        </div>
                        <RelationTable
                            columns={['Rang', 'Ouvrier', 'Commune', 'Score', 'Tarif net', 'Statut']}
                            empty={{ icon: 'join_inner', title: 'Aucun candidat', text: search.lastRun ? 'Aucun ouvrier disponible sur cette période et à ce tarif.' : 'Lancez la recherche pour classer les ouvriers disponibles.' }}
                            rows={search.suggestions.map((c) => ({
                                key: c.id,
                                cells: [<TextCell>{c.rank}</TextCell>, <StackCell main={c.name} sub={[c.code, c.phone].filter(Boolean).join(' · ')} strong />, <StackCell main={c.commune ?? '—'} sub={c.distance != null ? `${c.distance} km` : undefined} />, <TextCell>{c.score}</TextCell>, <TextCell>{fcfa(c.rate)}</TextCell>, <StatusBadge label={c.status.label} tone={c.status.tone as never} />],
                                actions: [
                                    ...(can.contact && OPEN.includes(c.status.value) ? [{ icon: 'call', label: 'Noter le contact', onClick: () => contact(c) }, { icon: 'how_to_reg', label: 'Enregistrer la réponse', onClick: () => respond(c) }] : []),
                                    { icon: 'visibility', label: 'Voir la fiche', onClick: () => router.visit(`/ouvriers/${c.workerId}`) },
                                ],
                            }))}
                        />
                    </section>
                )}

                <section style={{ ...stack, background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 }}>
                    <Block icon="info" title="Affectation">
                        <KeyValues
                            items={[
                                ['Métier', a.trade],
                                ['Poste', a.role],
                                ['Période prévue', `${day(a.startsOn)} → ${day(a.endsOn)}`],
                                ['Début réel', a.actualStart ? formatDate(a.actualStart) : null],
                                ['Fin réelle', a.actualEnd ? formatDate(a.actualEnd) : null],
                                ['Motif de fin', a.endReason],
                                ['Tarif net ouvrier', fcfa(a.netRate)],
                                ['Tarif promoteur', fcfa(a.promoterRate)],
                                ['Remplace', a.replaces?.worker],
                                ['Téléphone', a.worker.phone],
                            ]}
                        />
                    </Block>
                    <Block icon="event_busy" title="Indisponibilités" count={String(a.unavailabilities.length)}>
                        <RelationTable
                            columns={['Date', 'Déclarée par', 'Motif', 'Commentaire', 'Statut']}
                            empty={{ icon: 'event_busy', title: 'Aucune indisponibilité', text: '' }}
                            rows={a.unavailabilities.map((u) => ({ key: u.id, cells: [<TextCell>{day(u.date)}</TextCell>, <TextCell>{u.declaredBy}</TextCell>, <TextCell muted={!u.reason}>{u.reason ?? '—'}</TextCell>, <TextCell muted={!u.comment}>{u.comment ?? '—'}</TextCell>, <TextCell>{u.status}</TextCell>] }))}
                        />
                    </Block>
                    <Block icon="swap_horiz" title="Remplacements" count={String(a.replacements.length)}>
                        <RelationTable
                            columns={['Ouvert le', 'Remplaçant', 'Statut']}
                            empty={{ icon: 'swap_horiz', title: 'Aucun remplacement', text: '' }}
                            rows={a.replacements.map((r) => ({ key: r.id, cells: [<TextCell>{day(r.date)}</TextCell>, <TextCell muted={!r.replacedBy}>{r.replacedBy?.full_name ?? '—'}</TextCell>, <TextCell>{r.status}</TextCell>] }))}
                        />
                    </Block>
                </section>
            </div>

            {dialog}
        </>
    );
}
