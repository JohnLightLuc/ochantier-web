import FormDialog from '@/components/overlays/FormDialog';
import Button from '@/components/ui/Button';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import type { LandingMessage } from '@/types/landing';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Props = { message: LandingMessage; can: { update: boolean } };

/** Fiche d’un message du formulaire de contact : contenu, coordonnées, délai de réponse, prise en charge et traitement. */
export default function LandingMessageShow({ message: m, can }: Props) {
    const [closing, setClosing] = useState(false);
    const base = `/landing-page/messages/${m.id}`;

    return (
        <>
            <Head title={`${m.reference} · ${m.subject}`} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                {can.update && m.can.start && <Button label="Prendre en charge" icon="front_hand" variant="secondary" onClick={() => router.post(`${base}/statut`, { status: 'en_cours' }, { preserveScroll: true })} />}
                {can.update && m.can.close && <Button label="Marquer comme traité" icon="task_alt" variant="primary" onClick={() => setClosing(true)} />}
                <Button label="Répondre par e-mail" icon="mail" variant="secondary" onClick={() => (window.location.href = `mailto:${m.email}?subject=${encodeURIComponent(`Re : ${m.subject}`)}`)} />
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,420px),1fr))', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)' }}>
                <Block full icon="mail" title={m.subject} count={m.reference}>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', alignItems: 'center', marginBottom: 'var(--space-3)' }}>
                        <StatusBadge label={m.status.label} tone={m.status.tone} />
                        {m.late && <StatusBadge label="En retard" tone="r" icon="schedule" />}
                        <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                            Reçu le {m.receivedAt ?? '—'} · réponse attendue avant le {m.dueAt ?? '—'}
                        </span>
                    </div>
                    <p style={{ margin: 0, whiteSpace: 'pre-wrap', fontSize: 'var(--fs-base)', lineHeight: 'var(--lh-normal)' }}>{m.message}</p>
                </Block>
                <Block icon="person" title="Expéditeur">
                    <KeyValues
                        items={[
                            ['Nom', m.name],
                            ['Profil', m.profile],
                            ['Entreprise', m.company],
                            ['Téléphone', m.phone],
                            ['E-mail', m.email],
                        ]}
                    />
                </Block>
                <Block icon="assignment_turned_in" title="Suivi">
                    <KeyValues
                        items={[
                            ['Suivi par', m.handledBy],
                            ['Traité le', m.handledAt],
                            ['Note interne', m.note],
                        ]}
                    />
                </Block>
            </div>

            {closing && (
                <FormDialog
                    title="Marquer ce message comme traité ?"
                    body="Indiquez ce qui a été fait (réponse envoyée, rappel, démonstration prévue…)."
                    icon="task_alt"
                    okLabel="Marquer comme traité"
                    url={`${base}/statut`}
                    extra={{ status: 'traite' }}
                    onClose={() => setClosing(false)}
                    fields={[{ name: 'note', label: 'Note interne', type: 'textarea', full: true, placeholder: 'Ex. Rappelé le 21/11, démonstration prévue lundi.' }]}
                />
            )}
        </>
    );
}
