import FormDialog from '@/components/overlays/FormDialog';
import Icon from '@/components/ui/Icon';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, formatRating } from '@/lib/format';
import { hash } from '@/lib/tone';
import { workerTone } from '@/lib/workerTones';
import type { Review } from '@/types/workers';
import type { CSSProperties } from 'react';
import { postAction } from './blocks';
import type { TabProps } from './tabs';

const CRITERIA = ['Ponctualité', 'Qualité du travail', 'Comportement', 'Respect des consignes', 'Productivité'];
const MONTHS = ['avr.', 'mai', 'juin', 'juil.', 'août', 'sept.'];

const box: CSSProperties = { padding: 'var(--space-4)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)' };
const caption: CSSProperties = { fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginBottom: 'var(--space-2)' };

/** Synthèse des notes et modération des avis. */
export default function ReviewsTab({ worker: w, demo, actions }: TabProps) {
    const ui = useAdminUi();
    const reviews = demo.reviews;
    const visible = reviews.filter((a) => a.status !== 'Masqué');
    const base = `/ouvriers/${w.slug}/avis`;

    // Répartition estimée à partir de la moyenne et du nombre d’avis, comme dans la maquette.
    const distribution = [5, 4, 3, 2, 1].map((n) => ({ n, count: Math.round((w.reviewsCount * Math.max(0, 1 - Math.abs(w.rating - n) / 1.6)) / 2.2) }));
    const maxCount = Math.max(1, ...distribution.map((d) => d.count));
    const evolution = MONTHS.map((m, i) => ({ m, v: w.rating ? Math.max(1, Math.min(5, w.rating - 0.3 + i * 0.06 + ((hash(w.slug + m) % 5) - 2) * 0.05)) : 0 }));
    const criteria = CRITERIA.map((label, i) => {
        const values = visible.filter((a) => !a.team).map((a) => a.criteria[i]);
        const v = values.length ? values.reduce((x, y) => x + y, 0) / values.length : 0;
        return { label, v };
    });

    const reasonDialog = (title: string, icon: string, okLabel: string, url: string, field: string, label: string, tone?: 'warning') =>
        actions.open(<FormDialog title={title} icon={icon} tone={tone} okLabel={okLabel} url={url} onClose={actions.close} fields={[{ name: field, label, type: 'textarea', required: true }]} />);

    const consult = (a: Review) =>
        ui.openPanel({
            kicker: `Avis · ${a.promoter}`,
            title: a.site,
            badges: [
                { label: a.team ? 'Mission en équipe' : 'Mission individuelle', tone: a.team ? 'p' : 'b' },
                { label: a.status, tone: workerTone(a.status) },
            ],
            text: `« ${a.comment} »`,
            meta: [
                ['Date', formatDate(a.date)],
                ['Note individuelle', `${formatRating(a.note)} / 5`],
                ...(a.team
                    ? ([
                          ['Note de l’équipe', `${formatRating(a.teamNote)} / 5`],
                          ['Équipe', a.teamName],
                      ] as [string, string][])
                    : CRITERIA.map((l, i) => [l, `${formatRating(a.criteria[i])} / 5`] as [string, string])),
                ...(a.reply ? ([['Réponse administrative', a.reply]] as [string, string][]) : []),
            ],
        });

    const reviewActions = (a: Review) => {
        const hidden = a.status === 'Masqué';
        return [
            { icon: 'open_in_new', label: 'Consulter', onClick: () => consult(a) },
            ...(a.status !== 'Signalé' ? [{ icon: 'flag', label: 'Signaler', onClick: () => reasonDialog('Signaler cet avis ?', 'flag', 'Signaler', `${base}/${a.id}/signalement`, 'reason', 'Motif du signalement', 'warning') }] : []),
            hidden
                ? { icon: 'visibility', label: 'Restaurer', onClick: () => postAction(`${base}/${a.id}/restauration`) }
                : {
                      icon: 'visibility_off',
                      label: 'Masquer',
                      onClick: () => ui.confirm({ tone: 'warning', icon: 'visibility_off', title: 'Masquer cet avis ?', body: 'L’avis ne sera plus visible publiquement ni pris en compte dans la moyenne.', ok: 'Masquer', onConfirm: () => postAction(`${base}/${a.id}/masquage`) }),
                  },
            { icon: 'reply', label: a.reply ? 'Modifier la réponse' : 'Ajouter une réponse administrative', onClick: () => reasonDialog('Réponse administrative', 'reply', 'Publier la réponse', `${base}/${a.id}/reponse`, 'reply', 'Réponse') },
            {
                icon: 'gavel',
                label: 'Ouvrir un litige',
                danger: true,
                onClick: () =>
                    actions.open(
                        <FormDialog
                            title="Ouvrir un litige"
                            body={`${a.promoter} ↔ ${w.name} · ${a.site}`}
                            icon="gavel"
                            tone="danger"
                            okLabel="Ouvrir le litige"
                            url={`${base}/${a.id}/litige`}
                            onClose={actions.close}
                            fields={[
                                { name: 'reason', label: 'Motif', type: 'select', required: true, options: ['Avis contesté', 'Paiement contesté', 'Pointage contesté', 'Comportement', 'Autre'] },
                                { name: 'priority', label: 'Priorité', type: 'select', required: true, options: ['Normale', 'Moyenne', 'Haute'] },
                                { name: 'description', label: 'Description', type: 'textarea', required: true },
                            ]}
                        />,
                    ),
            },
        ];
    };

    const star = (size: string) => <Icon name="star" size={size} color="var(--c-accent-600)" />;
    const pill: CSSProperties = { padding: '2px var(--space-2)', borderRadius: 'var(--radius-pill)', fontSize: 'var(--fs-xs)' };

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <div data-component="WorkerRatingSummary" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,250px),1fr))', gap: 'var(--space-3)' }}>
                <div style={{ ...box, display: 'flex', flexDirection: 'column', gap: 'var(--space-1)' }}>
                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Moyenne générale</span>
                    <span style={{ display: 'flex', alignItems: 'baseline', gap: 'var(--space-1_5)' }}>
                        <b style={{ fontSize: 'var(--fs-5xl)', lineHeight: 1 }}>{formatRating(w.rating)}</b>
                        <span style={{ color: 'var(--c-muted)' }}>/ 5</span>
                        {star('var(--icon-md)')}
                    </span>
                    <span style={{ fontSize: 'var(--fs-md)' }}>{w.reviewsCount} avis au total</span>
                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                        {reviews.filter((a) => a.status === 'Signalé').length} signalé(s) · {reviews.filter((a) => a.status === 'Masqué').length} masqué(s)
                    </span>
                </div>
                <div style={box}>
                    <div style={caption}>Répartition des notes</div>
                    {distribution.map((d) => (
                        <div key={d.n} style={{ display: 'grid', gridTemplateColumns: '28px minmax(0,1fr) 28px', gap: 'var(--space-2)', alignItems: 'center', fontSize: 'var(--fs-sm)', padding: '2px 0' }}>
                            <span>{d.n} ★</span>
                            <span style={{ height: 8, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden' }}>
                                <span style={{ display: 'block', height: '100%', width: `${(d.count / maxCount) * 100}%`, background: 'var(--c-accent-600)' }} />
                            </span>
                            <span style={{ textAlign: 'right', color: 'var(--c-muted)' }}>{d.count}</span>
                        </div>
                    ))}
                </div>
                <div style={box}>
                    <div style={caption}>Évolution (6 derniers mois)</div>
                    <div style={{ height: 96, display: 'flex', alignItems: 'flex-end', gap: 'var(--space-2)' }}>
                        {evolution.map((e) => (
                            <div key={e.m} title={`${e.m} 2026 · ${e.v ? e.v.toFixed(1) : '—'}`} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 'var(--space-1)', height: '100%', justifyContent: 'flex-end' }}>
                                <span style={{ fontSize: 'var(--fs-2xs)', color: 'var(--c-muted)' }}>{formatRating(e.v)}</span>
                                <span style={{ width: '100%', maxWidth: 26, borderRadius: 'var(--radius-xs) var(--radius-xs) 0 0', background: 'var(--c-primary-500)', height: (e.v / 5) * 70 }} />
                                <span style={{ fontSize: 'var(--fs-2xs)', color: 'var(--c-muted)' }}>{e.m}</span>
                            </div>
                        ))}
                    </div>
                </div>
                <div style={box}>
                    <div style={caption}>Critères (missions individuelles)</div>
                    {criteria.map((c) => (
                        <div key={c.label} style={{ fontSize: 'var(--fs-sm)', padding: '2px 0' }}>
                            <span style={{ display: 'flex', justifyContent: 'space-between' }}>
                                <span>{c.label}</span>
                                <b>{formatRating(c.v)}</b>
                            </span>
                            <span style={{ display: 'block', height: 6, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden', marginTop: 2 }}>
                                <span style={{ display: 'block', height: '100%', width: `${(c.v / 5) * 100}%`, background: 'var(--c-primary-500)' }} />
                            </span>
                        </div>
                    ))}
                </div>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                {reviews.map((a) => (
                    <article key={a.id} style={{ border: `1px solid ${a.status === 'Signalé' ? 'var(--c-warning-line)' : 'var(--c-line)'}`, borderRadius: 'var(--radius-xl)', padding: 'var(--space-4)', background: a.status === 'Masqué' ? 'var(--c-surface-2)' : 'var(--c-surface)', display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
                            <StatusBadge label={a.team ? 'Mission en équipe' : 'Mission individuelle'} tone={a.team ? 'p' : 'b'} />
                            <b>{a.site}</b>
                            <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                {a.promoter} · {formatDate(a.date)}
                            </span>
                            <span style={{ flex: 1 }} />
                            <StatusBadge label={a.status} tone={workerTone(a.status)} />
                        </div>

                        {a.team ? (
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,260px),1fr))', gap: 'var(--space-3)' }}>
                                <div style={{ padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface-2)' }}>
                                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Note globale de l’équipe · {a.teamName}</div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', margin: 'var(--space-1) 0' }}>
                                        {star('var(--icon-sm)')}
                                        <b style={{ fontSize: 'var(--fs-xl)' }}>{formatRating(a.teamNote)}</b>
                                    </div>
                                    <p style={{ margin: 0, fontSize: 'var(--fs-md)' }}>« {a.teamComment} »</p>
                                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1_5)', marginTop: 'var(--space-2)' }}>
                                        {a.members.map(([name, note]) => (
                                            <span key={name} style={{ ...pill, background: 'var(--c-surface)', border: '1px solid var(--c-line)' }}>
                                                {name} · ★ {formatRating(note)}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                                <div style={{ padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', border: '1px dashed var(--c-line-strong)' }}>
                                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Note individuelle de l’ouvrier</div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', margin: 'var(--space-1) 0' }}>
                                        {star('var(--icon-sm)')}
                                        <b style={{ fontSize: 'var(--fs-xl)' }}>{formatRating(a.note)}</b>
                                    </div>
                                    <p style={{ margin: 0, fontSize: 'var(--fs-md)' }}>« {a.comment} »</p>
                                </div>
                            </div>
                        ) : (
                            <div style={{ display: 'flex', gap: 'var(--space-4)', flexWrap: 'wrap', alignItems: 'flex-start' }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)' }}>
                                    {star('var(--icon-md)')}
                                    <b style={{ fontSize: 'var(--fs-2xl)' }}>{formatRating(a.note)}</b>
                                </div>
                                <div style={{ flex: 1, minWidth: 240 }}>
                                    <p style={{ margin: '0 0 var(--space-2)', fontSize: 'var(--fs-md)' }}>« {a.comment} »</p>
                                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1_5)' }}>
                                        {CRITERIA.map((l, i) => (
                                            <span key={l} style={{ ...pill, background: 'var(--c-surface-3)' }}>
                                                {l} <b>{formatRating(a.criteria[i])}</b>
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        )}

                        {a.reply && (
                            <div style={{ padding: 'var(--space-2_5) var(--space-3)', borderRadius: 'var(--radius-md)', background: 'var(--c-info-bg)', fontSize: 'var(--fs-md)' }}>
                                <b style={{ color: 'var(--c-info-fg)' }}>Réponse administrative</b> · {a.reply}
                            </div>
                        )}

                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-4)' }}>
                            {reviewActions(a).map((b) => (
                                <button key={b.label} type="button" onClick={b.onClick} className="och-hover-underline" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)', border: 0, background: 'transparent', padding: 0, color: 'danger' in b && b.danger ? 'var(--c-danger-fg)' : 'var(--c-primary-600)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}>
                                    <Icon name={b.icon} size="var(--icon-xs)" />
                                    {b.label}
                                </button>
                            ))}
                        </div>
                    </article>
                ))}
                {!reviews.length && <StateBlock kind="nodata" icon="reviews" title="Aucun avis" text="Cet ouvrier n’a encore reçu aucune évaluation." />}
            </div>
        </div>
    );
}
