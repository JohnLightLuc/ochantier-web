import { ReasonDialog } from '@/components/promoters/dialogs';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { LandingApplication, LandingFile } from '@/types/landing';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const REFUSAL_CATEGORIES = ['Pièce d’identité illisible ou invalide', 'Doublon d’une autre candidature', 'Métier non couvert', 'Informations incomplètes', 'Autre'];

type Props = { application: LandingApplication; can: { validate: boolean; create: boolean } };

/**
 * Fiche d’une candidature « Devenir ouvrier » : identité, profil, pièces, doublons possibles, et décisions (prise en
 * charge, refus, création du profil ouvrier avec le formulaire prérempli).
 */
export default function LandingApplicationShow({ application: a, can }: Props) {
    const ui = useAdminUi();
    const [refusing, setRefusing] = useState(false);
    const base = `/landing-page/candidatures/${a.id}`;
    const file = (label: string, f: LandingFile) =>
        f ? (
            <Button key={label} label={label} icon={f.mime_type === 'application/pdf' ? 'picture_as_pdf' : 'image'} variant="secondary" onClick={() => window.open(f.url, '_blank', 'noopener')} />
        ) : null;

    return (
        <>
            <Head title={`${a.reference} · ${a.name}`} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                {can.validate && a.can.refuse && <Button label="Refuser" icon="person_cancel" variant="danger-soft" onClick={() => setRefusing(true)} />}
                {can.validate && a.can.review && (
                    <Button label="Prendre en charge" icon="fact_check" variant="secondary" onClick={() => router.post(`${base}/prise-en-charge`, {}, { preserveScroll: true })} />
                )}
                {can.create && a.can.convert && (
                    <Button
                        label="Créer le profil ouvrier"
                        icon="person_add"
                        variant="primary"
                        onClick={() =>
                            a.duplicateWorkers.length
                                ? ui.confirm({
                                      tone: 'warning',
                                      icon: 'content_copy',
                                      title: 'Un ouvrier a déjà ce numéro',
                                      body: `${a.duplicateWorkers.map((w) => `${w.name} (${w.reference})`).join(', ')}. Créer quand même un nouveau profil ?`,
                                      ok: 'Créer quand même',
                                      onConfirm: () => router.visit(`/ouvriers/nouveau?candidature=${a.id}`),
                                  })
                                : router.visit(`/ouvriers/nouveau?candidature=${a.id}`)
                        }
                    />
                )}
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                <Block full icon="engineering" title={a.name} count={a.reference}>
                    <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2)' }}>
                        <StatusBadge label={a.status.label} tone={a.status.tone} />
                        <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Reçue le {a.receivedAt ?? '—'}</span>
                        {a.handledBy && <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>· suivie par {a.handledBy}</span>}
                        {a.worker && (
                            <Link href={`/ouvriers/${a.worker.id}`} style={{ color: 'var(--c-primary)', fontWeight: 'var(--fw-semibold)' }}>
                                · profil ouvrier {a.worker.reference}
                            </Link>
                        )}
                    </div>
                    {a.refusalReason && <p style={{ margin: 'var(--space-3) 0 0', color: 'var(--c-danger-fg)', fontSize: 'var(--fs-md)' }}>Motif du refus : {a.refusalReason}</p>}
                    {(a.duplicateApplications.length > 0 || a.duplicateWorkers.length > 0) && (
                        <div role="alert" style={{ marginTop: 'var(--space-3)', padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-orange-bg)', color: 'var(--c-orange-fg)', fontSize: 'var(--fs-md)', display: 'flex', flexDirection: 'column', gap: 'var(--space-1)' }}>
                            <b>Doublon possible : même téléphone</b>
                            {a.duplicateApplications.map((d) => (
                                <Link key={d.id} href={`/landing-page/candidatures/${d.id}`} style={{ color: 'inherit' }}>
                                    Candidature {d.reference} du {d.date ?? '—'} ({d.status})
                                </Link>
                            ))}
                            {a.duplicateWorkers.map((w) => (
                                <Link key={w.id} href={`/ouvriers/${w.id}`} style={{ color: 'inherit' }}>
                                    Ouvrier {w.name} ({w.reference}, {w.status})
                                </Link>
                            ))}
                        </div>
                    )}
                </Block>
                <Block icon="badge" title="Identité">
                    <KeyValues items={a.identity} />
                </Block>
                <Block icon="construction" title="Métier et profil">
                    <KeyValues items={a.profile} />
                </Block>
                <Block icon="location_on" title="Zone, disponibilité et paiement">
                    <KeyValues items={a.work} />
                </Block>
                <Block icon="folder_shared" title="Pièces envoyées">
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                        {file('Pièce d’identité', a.files.identity)}
                        {file('Photo', a.files.photo)}
                        {!a.files.photo && <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Aucune photo envoyée.</span>}
                    </div>
                    <p style={{ margin: 'var(--space-3) 0 0', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Liens valables 30 minutes. Les pièces sont ajoutées au dossier de l’ouvrier à la création de son profil.</p>
                </Block>
            </div>

            {refusing && (
                <ReasonDialog
                    title={`Refuser la candidature ${a.reference} ?`}
                    body="La candidature est close ; le motif reste visible dans le Back Office."
                    icon="person_cancel"
                    okLabel="Refuser la candidature"
                    reasonLabel="Motif du refus *"
                    categories={REFUSAL_CATEGORIES}
                    url={`${base}/refus`}
                    placeholder="Précisez le motif."
                    onClose={() => setRefusing(false)}
                />
            )}
        </>
    );
}
