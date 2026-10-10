import FormDialog from '@/components/overlays/FormDialog';
import { TextCell } from '@/components/list/Table';
import Avatar from '@/components/ui/Avatar';
import Button, { type ButtonVariant } from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatNumber, formatRating } from '@/lib/format';
import { workerTone } from '@/lib/workerTones';
import type { ReplacementCase } from '@/types/workers';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { DataTable, Toolbar, postAction } from './blocks';
import type { TabProps } from './tabs';

const STEPS = ['Déclaration', 'Analyse de l’impact', 'Recherche d’ouvriers', 'Proposition de remplaçants', 'Validation administrative', 'Validation du promoteur', 'Confirmation du remplaçant', 'Transfert des informations', 'Clôture'];

const DESCRIPTIONS = [
    'L’indisponibilité a été déclarée. Analysez son impact sur le chantier avant de rechercher un remplaçant.',
    'Évaluez la durée d’absence, l’avancement et la criticité du poste.',
    'Le moteur de matching recherche les ouvriers compatibles.',
    'Sélectionnez le remplaçant le plus adapté parmi les profils compatibles.',
    'Validez administrativement le remplaçant proposé.',
    'Le promoteur doit valider le remplaçant pour les missions contractuelles ou de plus de 5 jours.',
    'Le remplaçant doit confirmer sa disponibilité depuis l’application mobile.',
    'Transférez les informations de mission (plans, horaires, consignes, contact du chef de chantier).',
    'Le remplacement est effectif. Clôturez le dossier pour l’archiver.',
];

const CLOSED = ['Clôturé', 'Annulé', 'Refusé'];

type Action = { label: string; icon: string; variant: ButtonVariant; onClick: () => void };

/** Dossiers d’indisponibilité et workflow de remplacement. */
export default function ReplacementsTab({ worker: w, demo, candidates, actions }: TabProps) {
    const ui = useAdminUi();
    const open = demo.replacements.filter((c) => !CLOSED.includes(c.status));
    const [selected, setSelected] = useState<string | null>(null);
    const current = open.find((c) => c.id === selected) ?? open[0];
    const archived = w.status.value === 'archived';
    const base = `/ouvriers/${w.slug}/remplacements`;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            {!archived && (
                <Toolbar
                    actions={[
                        { label: 'Demander un remplacement', icon: 'swap_horiz', onClick: () => actions.unavailability(w, demo.assignments, true) },
                        { label: 'Déclarer une indisponibilité', icon: 'event_busy', variant: 'primary', onClick: () => actions.unavailability(w, demo.assignments) },
                    ]}
                />
            )}

            <div data-component="ReplacementWorkflow" style={{ border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', padding: 'var(--space-4)' }}>
                {current ? (
                    <Workflow worker={w} item={current} candidates={candidates} base={base} actions={actions} ui={ui} />
                ) : (
                    <StateBlock
                        kind="nodata"
                        icon="swap_horiz"
                        title="Aucun remplacement en cours"
                        text="Déclarez une indisponibilité liée à une affectation pour lancer un workflow de remplacement."
                        actionLabel={archived ? undefined : 'Déclarer une indisponibilité'}
                        onAction={() => actions.unavailability(w, demo.assignments, true)}
                    />
                )}
            </div>

            <DataTable
                icon="history"
                title="Historique des remplacements"
                count={`${demo.replacements.length} dossier(s)`}
                minWidth={1000}
                columns={['Référence', 'Chantier', 'Moment', 'Période', 'Motif', 'Urgence', 'Remplaçant', 'Statut']}
                empty={{ icon: 'swap_horiz', title: 'Aucun remplacement', text: 'Aucune indisponibilité n’a nécessité de remplacement.' }}
                rows={demo.replacements.map((c) => ({
                    key: c.id,
                    onOpen: CLOSED.includes(c.status) ? undefined : () => setSelected(c.id),
                    cells: [
                        <TextCell strong>{c.id}</TextCell>,
                        <TextCell>{c.site}</TextCell>,
                        <TextCell>{c.moment}</TextCell>,
                        <TextCell>
                            {formatDate(c.start)} → {formatDate(c.end)}
                        </TextCell>,
                        <TextCell>{c.reason}</TextCell>,
                        <StatusBadge label={c.urgency} tone={workerTone(c.urgency)} />,
                        <TextCell muted={!c.chosen}>{c.chosen?.name ?? '—'}</TextCell>,
                        <StatusBadge label={c.status} tone={workerTone(c.status)} />,
                    ],
                    actions: CLOSED.includes(c.status)
                        ? []
                        : [
                              {
                                  icon: 'visibility',
                                  label: 'Suivre le workflow',
                                  onClick: () => {
                                      setSelected(c.id);
                                      window.scrollTo({ top: 0, behavior: 'smooth' });
                                  },
                              },
                          ],
                }))}
            />
        </div>
    );
}

type WorkflowProps = Pick<TabProps, 'worker' | 'candidates' | 'actions'> & { item: ReplacementCase; base: string; ui: ReturnType<typeof useAdminUi> };

function Workflow({ worker: w, item: c, candidates, base, actions, ui }: WorkflowProps) {
    const url = `${base}/${c.id}`;
    const step = Math.min(9, c.step);
    const days = Math.max(1, Math.round((new Date(c.end).getTime() - new Date(c.start).getTime()) / 864e5) + 1);
    const advance = (action: string) => postAction(`${url}/${action}`);

    const stepActions: Action[] = [];
    if (step === 1) stepActions.push({ label: 'Lancer l’analyse d’impact', icon: 'analytics', variant: 'primary', onClick: () => advance('analyser') });
    if (step === 2) stepActions.push({ label: 'Rechercher des ouvriers compatibles', icon: 'person_search', variant: 'primary', onClick: () => advance('rechercher') });
    if (step === 5) {
        stepActions.push(
            {
                label: 'Refuser',
                icon: 'close',
                variant: 'danger-soft',
                onClick: () => actions.open(<FormDialog title="Refuser le remplaçant ?" icon="close" tone="danger" okLabel="Refuser" url={`${url}/refus`} onClose={actions.close} fields={[{ name: 'reason', label: 'Motif du refus', type: 'textarea', required: true }]} />),
            },
            { label: 'Valider le remplaçant', icon: 'verified', variant: 'primary', onClick: () => advance('valider') },
        );
    }
    if (step === 6) {
        stepActions.push({ label: 'Validation non requise', icon: 'skip_next', variant: 'secondary', onClick: () => advance('valider-promoteur') }, { label: 'Enregistrer la validation du promoteur', icon: 'how_to_reg', variant: 'primary', onClick: () => advance('valider-promoteur') });
    }
    if (step === 7) stepActions.push({ label: 'Confirmer le remplaçant', icon: 'task_alt', variant: 'primary', onClick: () => advance('confirmer') });
    if (step === 8) stepActions.push({ label: 'Transférer les informations de mission', icon: 'forward_to_inbox', variant: 'primary', onClick: () => advance('transferer') });
    if (step === 9) {
        stepActions.push({
            label: 'Clôturer le remplacement',
            icon: 'lock',
            variant: 'primary',
            onClick: () => ui.confirm({ icon: 'lock', title: 'Clôturer ce remplacement ?', body: 'L’affectation d’origine passera au statut « Remplacée ».', ok: 'Clôturer', onConfirm: () => postAction(`${url}/cloture`) }),
        });
    }

    const meta: [string, string][] = [
        ['Période', `${formatDate(c.start)} → ${formatDate(c.end)}`],
        ['Motif', c.reason],
        ['Urgence', c.urgency],
        ['Reprise', c.recovery],
    ];

    return (
        <>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
                <Icon name="swap_horiz" size="var(--icon-lg)" color="var(--c-primary)" />
                <div style={{ flex: 1, minWidth: 220 }}>
                    <h3 style={{ margin: 0, fontSize: 'var(--fs-xl)' }}>Remplacement {c.id}</h3>
                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        {c.moment} · {c.site}
                    </div>
                </div>
                <StatusBadge label={c.status} tone={workerTone(c.status)} />
                {c.step < 9 && (
                    <Button
                        label="Annuler le remplacement"
                        size="sm"
                        variant="danger-soft"
                        onClick={() => ui.confirm({ tone: 'danger', icon: 'cancel', title: 'Annuler ce remplacement ?', body: 'L’affectation d’origine sera restaurée.', ok: 'Annuler le remplacement', cancel: 'Retour', onConfirm: () => postAction(`${url}/annulation`) })}
                    />
                )}
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-5)', marginTop: 'var(--space-3)' }}>
                {meta.map(([k, v]) => (
                    <span key={k} style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        {k} : <b style={{ color: 'var(--c-ink)', fontWeight: 'var(--fw-semibold)' }}>{v}</b>
                    </span>
                ))}
            </div>

            <ol style={{ listStyle: 'none', margin: 'var(--space-4) 0', padding: 0, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(104px,1fr))', gap: 'var(--space-2)' }}>
                {STEPS.map((label, i) => {
                    const n = i + 1;
                    const done = n < c.step;
                    const isCurrent = n === c.step;
                    return (
                        <li key={label} aria-current={isCurrent ? 'step' : undefined} style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 'var(--space-1)', padding: 'var(--space-2)', borderRadius: 'var(--radius-md)', background: isCurrent ? 'var(--c-primary-50)' : 'transparent', minWidth: 0 }}>
                            <span style={{ width: 26, height: 26, borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-bold)', background: done ? 'var(--c-primary)' : isCurrent ? 'var(--c-accent)' : 'var(--c-surface-3)', color: done ? 'var(--c-on-dark)' : isCurrent ? 'var(--c-primary-strong)' : 'var(--c-muted)' }}>{done ? '✓' : n}</span>
                            <span style={{ fontSize: 'var(--fs-xs)', fontWeight: isCurrent ? 700 : 500, lineHeight: 1.25 }}>{label}</span>
                            {c.times[i] && <span style={{ fontSize: 'var(--fs-2xs)', color: 'var(--c-muted)' }}>{c.times[i]}</span>}
                        </li>
                    );
                })}
            </ol>

            <div style={{ padding: 'var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface-2)', display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                <b style={{ fontSize: 'var(--fs-lg)' }}>
                    Étape {step} · {STEPS[step - 1]}
                </b>
                <p style={{ margin: 0, fontSize: 'var(--fs-md)', color: 'var(--c-ink-2)', textWrap: 'pretty' }}>{DESCRIPTIONS[step - 1]}</p>

                {step === 2 && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                        {[
                            ['Jours d’absence', String(days)],
                            ['Poste', w.trade],
                            ['Criticité', c.urgency],
                        ].map(([k, v]) => (
                            <span key={k} style={{ padding: 'var(--space-1_5) var(--space-3)', borderRadius: 'var(--radius-md)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', fontSize: 'var(--fs-sm)' }}>
                                {k} : <b>{v}</b>
                            </span>
                        ))}
                    </div>
                )}

                {step === 4 && (
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,270px),1fr))', gap: 'var(--space-3)' }}>
                        {candidates.map((o) => (
                            <div key={o.slug} data-component="ReplacementCandidateCard" style={{ border: '1.5px solid var(--c-line)', borderRadius: 'var(--radius-xl)', padding: 'var(--space-3)', background: 'var(--c-surface)', display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', minWidth: 0 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)' }}>
                                    <Avatar name={o.name} />
                                    <div style={{ flex: 1, minWidth: 0 }}>
                                        <b style={{ display: 'block' }}>{o.name}</b>
                                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                            {o.trade} · {o.experienceYears} ans · {o.commune}
                                        </span>
                                    </div>
                                    <span style={{ padding: '2px var(--space-2)', borderRadius: 'var(--radius-sm)', background: 'var(--c-success-bg)', color: 'var(--c-success-fg)', fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-sm)' }}>{o.score} %</span>
                                </div>
                                <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>
                                    {formatNumber(o.dailyRate)} FCFA/jour · ★ {formatRating(o.rating)} · {o.availability}
                                </div>
                                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-1_5) var(--space-3)' }}>
                                    {o.criteria.map(([label, value]) => (
                                        <div key={label} style={{ fontSize: 'var(--fs-xs)' }}>
                                            <span style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--c-muted)' }}>
                                                {label}
                                                <b style={{ color: 'var(--c-ink)' }}>{value} %</b>
                                            </span>
                                            <span style={{ display: 'block', height: 4, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden', marginTop: 2 }}>
                                                <span style={{ display: 'block', height: '100%', width: `${value}%`, background: 'var(--c-primary-500)' }} />
                                            </span>
                                        </div>
                                    ))}
                                </div>
                                <div style={{ display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
                                    <Button label="Voir le profil" size="sm" variant="secondary" onClick={() => router.visit(`/ouvriers/${o.slug}`)} />
                                    <Button label="Proposer" size="sm" variant="primary" onClick={() => postAction(`${url}/candidat`, { candidate: o.slug })} />
                                </div>
                            </div>
                        ))}
                        {!candidates.length && <StateBlock kind="nodata" compact icon="person_search" title="Aucun profil compatible" text="Aucun ouvrier validé ne correspond à ce poste pour le moment." />}
                    </div>
                )}

                {c.chosen && c.step > 4 && (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', border: '1px solid var(--c-primary-200)' }}>
                        <Avatar name={c.chosen.name} />
                        <span style={{ flex: 1, fontSize: 'var(--fs-md)' }}>
                            Remplaçant retenu : <b>{c.chosen.name}</b> · {c.chosen.detail}
                        </span>
                        <span style={{ padding: '2px var(--space-2)', borderRadius: 'var(--radius-sm)', background: 'var(--c-success-bg)', color: 'var(--c-success-fg)', fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-sm)' }}>{c.chosen.score} %</span>
                    </div>
                )}

                {stepActions.length > 0 && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                        {stepActions.map((a) => (
                            <Button key={a.label} label={a.label} icon={a.icon} variant={a.variant} onClick={a.onClick} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
