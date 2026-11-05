import { TextCell } from '@/components/list/Table';
import { RelationTable, stack } from '@/components/promoters/RelationTabs';
import Button from '@/components/ui/Button';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import { duration } from '@/pages/Attendances/Index';
import { Head, router } from '@inertiajs/react';

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
    anomalies: { id: string; type: string; status: string; detectedBy: string; comment: string | null; resolution: string | null }[];
    corrections: { id: string; status: string; reason: string; date: string | null }[];
    canRemind: boolean;
};

type Props = { attendance: Attendance; can: { update: boolean; export: boolean } };

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
export default function AttendanceShow({ attendance: a, can }: Props) {
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
                            rows={a.anomalies.map((x) => ({ key: x.id, cells: [<TextCell>{x.type}</TextCell>, <TextCell>{x.detectedBy}</TextCell>, <TextCell muted={!x.comment}>{x.comment ?? '—'}</TextCell>, <TextCell muted={!x.resolution}>{x.resolution ?? '—'}</TextCell>, <TextCell>{x.status}</TextCell>] }))}
                        />
                    </Block>
                    <Block icon="edit_note" title="Corrections" count={String(a.corrections.length)}>
                        <RelationTable
                            columns={['Date', 'Motif', 'Statut']}
                            empty={{ icon: 'edit_note', title: 'Aucune correction', text: '' }}
                            rows={a.corrections.map((x) => ({ key: x.id, cells: [<TextCell>{x.date ? formatDate(x.date) : '—'}</TextCell>, <TextCell>{x.reason}</TextCell>, <TextCell>{x.status}</TextCell>] }))}
                        />
                    </Block>
                </section>
            </div>
        </>
    );
}
