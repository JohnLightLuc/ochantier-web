import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import { TextCell } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { formatDate, formatNumber } from '@/lib/format';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

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
    can: { declare_unavailability: boolean };
};

type Props = { assignment: Assignment; reasons: Option[]; can: { update: boolean; export: boolean } };

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
