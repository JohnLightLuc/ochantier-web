import { TextCell } from '@/components/list/Table';
import FormDialog from '@/components/overlays/FormDialog';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import { anomalyActions } from '@/pages/Attendances/Anomalies';
import { correctionActions } from '@/pages/Attendances/Corrections';
import { duration } from '@/pages/Attendances/Index';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Correction = { id: string; status: { value: string; label: string; tone: string }; reason: string; changes: string; requestedBy: string | null; decidedBy: string | null; isPending: boolean; canApprove: boolean };

type Anomaly = { id: string; type: string; status: { value: string; label: string; tone: string }; detectedBy: string; comment: string | null; resolution: string | null; isOpen: boolean };

type Proof = { time: string | null; distance: number | null; accuracy: number | null; outOfZone: boolean; author: string; photo: string | null; signature: string | null; status: string };

type Attendance = {
    id: string;
    date: string;
    status: { value: string; label: string; tone: string };
    worker: { id: string; reference: string; full_name: string };
    site: { id: string; label: string; commune: string | null };
    promoter: { name: string };
    trade: string | null;
    arrival: string | null;
    departure: string | null;
    minutes: number | null;
    outOfZone: boolean;
    openAnomalies: number;
    locked: boolean;
    assignmentId: string;
    observation: string | null;
    validatedAt: string | null;
    validatedBy: string | null;
    arrivalProof: Proof | null;
    departureProof: Proof | null;
    anomalies: Anomaly[];
    corrections: Correction[];
    canRemind: boolean;
    correct: { minutes: boolean; status: string | null };
};

type Props = { attendance: Attendance; reportTypes: Option[]; can: { update: boolean; export: boolean; validate: boolean } };

function ProofCard({ title, proof }: { title: string; proof: Proof | null }) {
    const image = (src: string | null, alt: string) => (src ? <a href={src} target="_blank" rel="noreferrer"><img src={src} alt={alt} style={{ width: '100%', height: 160, objectFit: 'cover', borderRadius: 'var(--radius-lg)', border: '1px solid var(--c-line)' }} /></a> : null);

    return (
        <Block icon="photo_camera" title={title}>
            {proof ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                    <KeyValues
                        items={[
                            ['Heure', proof.time],
                            ['Pointé par', proof.author],
                            ['Distance au chantier', proof.distance != null ? `${proof.distance} m${proof.outOfZone ? ' (hors zone)' : ''}` : null],
                            ['Précision GPS', proof.accuracy != null ? `± ${proof.accuracy} m` : null],
                            ['Statut', proof.status],
                        ]}
                    />
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 'var(--space-3)' }}>
                        {image(proof.photo, `Photo : ${title}`)}
                        {image(proof.signature, `Signature : ${title}`)}
                    </div>
                </div>
            ) : (
                <TextCell muted>Non pointé</TextCell>
            )}
        </Block>
    );
}

/** Fiche d’une journée : preuves, validation par le promoteur, anomalies et corrections. */
export default function AttendanceShow({ attendance: a, reportTypes, can }: Props) {
    const [dialog, setDialog] = useState<ReactNode>(null);
    const close = () => setDialog(null);

    const report = () =>
        setDialog(
            <FormDialog
                title="Signaler une anomalie"
                body="L’anomalie ne bloque ni la validation ni le paiement."
                icon="report"
                tone="warning"
                okLabel="Signaler"
                url={`/pointages/${a.id}/anomalies`}
                onClose={close}
                fields={[
                    { name: 'type', label: 'Type', type: 'select', required: true, full: true, options: reportTypes },
                    { name: 'comment', label: 'Description', type: 'textarea', required: true },
                ]}
            />,
        );

    const correct = () =>
        setDialog(
            <FormDialog
                title="Demander une correction"
                body="Une autre personne doit l’approuver. L’ancienne valeur reste conservée."
                icon="edit_note"
                tone="warning"
                okLabel="Demander"
                url={`/pointages/${a.id}/corrections`}
                onClose={close}
                fields={[
                    ...(a.correct.minutes ? [{ name: 'hours', label: `Durée en heures (actuelle : ${duration(a.minutes)})`, type: 'number' as const, full: true }] : []),
                    ...(a.correct.status ? [{ name: 'status', label: 'Statut', type: 'select' as const, full: true, options: [{ value: a.correct.status, label: a.correct.status === 'rejete' ? 'Rejeter la journée' : 'Rétablir la journée validée' }] }] : []),
                    { name: 'reason', label: 'Motif', type: 'textarea' as const, required: true },
                ]}
            />,
        );

    return (
        <>
            <Head title={`Pointage · ${a.worker.full_name}`} />
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                <section style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-3)', alignItems: 'center', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                    <div style={{ flex: '1 1 320px', minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                            <h2 style={{ margin: 0, fontSize: 'var(--fs-3xl)', fontWeight: 'var(--fw-bold)' }}>{a.worker.full_name}</h2>
                            <StatusBadge label={a.status.label} tone={a.status.tone as never} />
                            {a.outOfZone && <StatusBadge label="Hors zone" tone="o" />}
                            {a.locked && <StatusBadge label="Facturée" tone="n" />}
                        </div>
                        <div style={{ marginTop: 'var(--space-1)', color: 'var(--c-ink-2)' }}>
                            {formatDate(a.date)} · {a.site.label} · {a.promoter.name}
                        </div>
                    </div>
                    <Button label="Affectation" icon="assignment_ind" variant="secondary" onClick={() => router.visit(`/affectations/${a.assignmentId}`)} />
                    <Button label="Chantier" icon="construction" variant="secondary" onClick={() => router.visit(`/chantiers/${a.site.id}`)} />
                    {can.update && (a.correct.minutes || a.correct.status) && <Button label="Demander une correction" icon="edit_note" variant="secondary" onClick={correct} />}
                    {can.update && <Button label="Signaler une anomalie" icon="report" variant="secondary" onClick={report} />}
                    {can.update && a.canRemind && <Button label="Relancer le promoteur" icon="notifications_active" variant="primary" onClick={() => router.post(`/pointages/${a.id}/relance`, {}, { preserveScroll: true })} />}
                </section>

                <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                    <StatCard label="Arrivée" value={a.arrival ?? '—'} icon="login" tone="b" />
                    <StatCard label="Départ" value={a.departure ?? '—'} icon="logout" tone="b" />
                    <StatCard label="Durée travaillée" value={duration(a.minutes)} icon="timer" tone="g" />
                    <StatCard label="Anomalies ouvertes" value={String(a.openAnomalies)} icon="report" tone="a" />
                </div>

                <section style={{ ...stack, background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 }}>
                    <Block icon="verified" title="Validation">
                        <KeyValues
                            items={[
                                ['Validée par', a.validatedBy],
                                ['Validée le', a.validatedAt ? formatDate(a.validatedAt) : null],
                                ['Observation', a.observation],
                            ]}
                        />
                    </Block>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(300px,1fr))', gap: 'var(--space-5)' }}>
                        <ProofCard title="Arrivée" proof={a.arrivalProof} />
                        <ProofCard title="Départ" proof={a.departureProof} />
                    </div>
                    <Block icon="report" title="Anomalies" count={String(a.anomalies.length)}>
                        <RelationTable
                            columns={['Type', 'Détectée par', 'Commentaire', 'Résolution', 'Statut']}
                            empty={{ icon: 'report', title: 'Aucune anomalie', text: '' }}
                            rows={a.anomalies.map((x) => ({ key: x.id, cells: [<TextCell>{x.type}</TextCell>, <TextCell>{x.detectedBy}</TextCell>, <TextCell muted={!x.comment}>{x.comment ?? '—'}</TextCell>, <TextCell muted={!x.resolution}>{x.resolution ?? '—'}</TextCell>, <StatusBadge label={x.status.label} tone={x.status.tone as never} />], actions: can.update ? anomalyActions(x, setDialog) : [] }))}
                        />
                    </Block>
                    <Block icon="edit_note" title="Corrections" count={String(a.corrections.length)}>
                        <RelationTable
                            columns={['Changement', 'Motif', 'Demandée par', 'Décidée par', 'Statut']}
                            empty={{ icon: 'edit_note', title: 'Aucune correction', text: '' }}
                            rows={a.corrections.map((x) => ({ key: x.id, cells: [<TextCell>{x.changes}</TextCell>, <TextCell>{x.reason}</TextCell>, <TextCell muted={!x.requestedBy}>{x.requestedBy ?? '—'}</TextCell>, <TextCell muted={!x.decidedBy}>{x.decidedBy ?? '—'}</TextCell>, <StatusBadge label={x.status.label} tone={x.status.tone as never} />], actions: can.validate ? correctionActions(x) : [] }))}
                        />
                    </Block>
                </section>
            </div>

            {dialog}
        </>
    );
}
