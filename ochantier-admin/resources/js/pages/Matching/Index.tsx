import ProfileCard from '@/components/matching/ProfileCard';
import { fcfa, useMatchingActions } from '@/components/matching/useMatchingActions';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import { SelectInput, TextInput } from '@/components/ui/Field';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import { toneColors } from '@/lib/tone';
import type { AnyTone } from '@/types';
import type { MatchingIndexProps, Profile } from '@/types/matching';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const panel = { background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', minWidth: 0 } as const;

/** Matching : besoins à pourvoir, profils du même métier proposés automatiquement (un ouvrier par poste) et validation groupée. */
export default function MatchingIndex({ filters, filterDefs, trades, sorts, sort, kpis, toValidate, needs, currentOffer, groups, sums, alerts }: MatchingIndexProps) {
    const ui = useAdminUi();
    const actions = useMatchingActions();
    const [listOpen, setListOpen] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);
    const [q, setQ] = useState(filters.q ?? '');
    const selected = needs.filter((n) => n.on);
    const allOn = needs.length > 0 && selected.length === needs.length;

    useEffect(() => setQ(filters.q ?? ''), [filters.q]);

    const apply = (changes: Record<string, string | undefined>) => {
        const next: Record<string, string | undefined> = { ...filters, ...changes };
        delete next.besoin;
        router.get('/matching', Object.fromEntries(Object.entries(next).filter(([k, v]) => v !== undefined && (v !== '' || k === 'besoins'))) as Record<string, string>, { preserveState: true, preserveScroll: true, replace: true });
    };

    const setNeeds = (slugs: string[]) => apply({ besoins: slugs.join(',') });
    const toggleNeed = (slug: string) => {
        const current = selected.map((n) => n.slug);
        setNeeds(current.includes(slug) ? current.filter((s) => s !== slug) : [...current, slug]);
    };
    const resetFilters = () => apply({ q: undefined, metier: undefined, ...Object.fromEntries(filterDefs.map((d) => [d.key, undefined])) });
    const toggleAlt = (offerId: number) => {
        const alt = (filters.alt ?? '').split(',').filter(Boolean);
        apply({ alt: (alt.includes(String(offerId)) ? alt.filter((x) => x !== String(offerId)) : [...alt, String(offerId)]).join(',') || undefined });
    };
    const activeFilters = filterDefs.filter((d) => filters[d.key]).length;

    const onToggle = (p: Profile, rest: number, chosen: number, trade: string) => {
        if (!p.selected && chosen >= rest) return ui.toast(`Nombre d’ouvriers recherchés atteint pour ${trade.toLowerCase()} : désélectionnez un ouvrier pour en choisir un autre.`, { tone: 'error' });
        if (p.replacement) return actions.replacement(p, null);
        actions.toggle(p);
    };

    const links: [string, string, string][] = [
        ['Matching groupé', 'play_circle', '/matching/lancer'],
        ['Résultats', 'list', '/matching/resultats'],
        [`À valider (${toValidate})`, 'hourglass_top', '/matching/a-valider'],
        ['Historique', 'history', '/matching/historique'],
        ['Analyses', 'insights', '/matching/analyses'],
        ...(currentOffer
            ? ([
                  ['Besoin sélectionné', 'assignment', `/matching/besoins/${currentOffer}`],
                  ['Comparer', 'compare', `/matching/besoins/${currentOffer}/comparaison`],
              ] as [string, string, string][])
            : []),
    ];
    const remaining = Math.max(0, sums.required - sums.selected);
    const gap = sums.available - sums.total;
    const summary: [string, string, string, string][] = [
        ['groups', 'var(--c-primary)', String(sums.required), 'ouvriers requis'],
        ['check_circle', 'var(--c-success-fg)', String(sums.selected), 'sélectionnés'],
        ['person_add', 'var(--c-warning-fg)', String(remaining), 'restants'],
        ...(sums.selected > sums.required ? ([['error', 'var(--c-danger-fg)', String(sums.selected - sums.required), 'excédentaires']] as [string, string, string, string][]) : []),
        ...(sums.replacements ? ([['swap_horiz', 'var(--c-muted)', String(sums.replacements), 'remplaçants (non comptés)']] as [string, string, string, string][]) : []),
        ['savings', sums.total > sums.available ? 'var(--c-danger-fg)' : 'var(--c-warning-fg)', fcfa(sums.total), `estimés / ${fcfa(sums.available)} disponibles (écart ${gap >= 0 ? '+' : ''}${fcfa(gap)})`],
        ['warning', sums.conflicts ? 'var(--c-danger-fg)' : 'var(--c-muted)', String(sums.conflicts), `conflit${sums.conflicts > 1 ? 's' : ''}`],
    ];
    const first = groups[0]?.offer;

    return (
        <>
            <Head title="Matching" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: -6 }}>
                <Button label="Actualiser" icon="refresh" variant="secondary" onClick={() => router.post('/matching/actualisation', {}, { preserveScroll: true })} />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                {kpis.map((k, i) => (
                    <button key={k.label} type="button" aria-label={k.label} onClick={() => (i === 3 ? router.visit('/matching/analyses') : resetFilters())} className="och-hover-card" style={{ display: 'block', minWidth: 0, padding: 0, border: 0, background: 'transparent', textAlign: 'left', borderRadius: 'var(--radius-2xl)', cursor: 'pointer' }}>
                        <StatCard label={k.label} value={k.value} icon={k.icon} tone={k.tone as AnyTone} hint={k.hint} />
                    </button>
                ))}
            </div>

            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                {links.map(([label, icon, href]) => (
                    <button key={label} type="button" onClick={() => router.visit(href)} className="och-hover-line" style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-3)', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-pill)', background: 'var(--c-surface)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer', color: 'var(--c-ink-2)' }}>
                        <Icon name={icon} size="var(--icon-xs)" />
                        {label}
                    </button>
                ))}
            </div>

            {alerts.map((a) => (
                <div key={a.title} role="alert" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-xl)', background: `var(--c-${a.tone}-bg)`, color: `var(--c-${a.tone}-fg)` }}>
                    <Icon name={a.icon} size="var(--icon-md)" />
                    <span style={{ flex: 1, minWidth: 200 }}>
                        <b>{a.title}</b> {a.text}
                    </span>
                    <button
                        type="button"
                        onClick={() => (a.action === 'refresh' ? router.post('/matching/actualisation', {}, { preserveScroll: true }) : router.visit(a.action === 'settings' ? '/parametres' : a.action))}
                        style={{ height: 'var(--control-sm)', padding: '0 var(--space-3)', border: '1px solid currentColor', borderRadius: 'var(--radius-md)', background: 'transparent', color: 'inherit', fontWeight: 'var(--fw-semibold)', cursor: 'pointer' }}
                    >
                        {a.cta}
                    </button>
                </div>
            ))}

            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'flex-start' }}>
                <section data-component="NeedsToFill" style={{ ...panel, flex: listOpen ? '1 1 340px' : '1 1 100%', padding: 'var(--space-4) var(--space-5)' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap' }}>
                        <Icon name="description" size="var(--icon-md)" color="var(--c-primary)" />
                        <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)' }}>Besoins à pourvoir</h2>
                        <span style={{ padding: '2px var(--space-2_5)', borderRadius: 'var(--radius-pill)', background: 'var(--c-primary-100)', color: 'var(--c-primary-600)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)' }}>
                            {selected.length} / {needs.length} sélectionné(s) · {sums.required} ouvrier(s) recherché(s)
                        </span>
                        <span style={{ flex: 1 }} />
                        <Button label={listOpen ? 'Masquer la liste' : 'Afficher la liste'} iconRight={listOpen ? 'expand_less' : 'expand_more'} size="sm" variant="secondary" aria-expanded={listOpen} onClick={() => setListOpen(!listOpen)} />
                    </div>

                    {!listOpen && needs.length > 0 && (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: 'var(--space-3)' }}>
                            {needs.map((n) => (
                                <button key={n.id} type="button" title={n.line} onClick={() => toggleNeed(n.slug)} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-3)', borderRadius: 'var(--radius-pill)', border: `1.5px solid ${n.on ? 'var(--c-primary-400)' : 'var(--c-line)'}`, background: n.on ? 'var(--c-primary-50)' : 'var(--c-surface)', cursor: 'pointer', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', color: 'var(--c-ink)' }}>
                                    <Icon name={n.on ? 'check_box' : 'check_box_outline_blank'} size="var(--icon-xs)" color={n.on ? 'var(--c-primary)' : 'var(--c-muted)'} />
                                    {n.short}
                                </button>
                            ))}
                        </div>
                    )}

                    {listOpen && (
                        <div style={{ marginTop: 'var(--space-3)', display: 'flex', flexDirection: 'column', gap: 'var(--space-2_5)' }}>
                            <TextInput value={q} onChange={(e) => setQ(e.target.value)} onBlur={() => q !== (filters.q ?? '') && apply({ q: q || undefined })} onKeyDown={(e) => e.key === 'Enter' && apply({ q: q || undefined })} placeholder="Rechercher un besoin ou un chantier" aria-label="Rechercher un besoin ou un chantier" />
                            <SelectInput value={filters.metier ?? ''} onChange={(e) => apply({ metier: e.target.value || undefined })} aria-label="Métier" options={[{ value: '', label: 'Tous les métiers' }, ...trades.map((t) => ({ value: t, label: t }))]} />
                            <div style={{ display: 'flex', gap: 'var(--space-3)' }}>
                                <button type="button" onClick={() => setMoreOpen(!moreOpen)} style={{ border: 0, background: 'transparent', padding: 0, color: 'var(--c-primary)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 'var(--space-1)' }}>
                                    <Icon name="tune" size="var(--icon-xs)" />
                                    {moreOpen ? 'Masquer les filtres' : `Plus de filtres${activeFilters ? ` (${activeFilters})` : ''}`}
                                </button>
                                <button type="button" onClick={resetFilters} style={{ border: 0, background: 'transparent', padding: 0, color: 'var(--c-muted)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}>
                                    Réinitialiser
                                </button>
                            </div>
                            {moreOpen && (
                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(140px,1fr))', gap: 'var(--space-2)' }}>
                                    {filterDefs.map((d) => (
                                        <SelectInput key={d.key} value={filters[d.key] ?? ''} onChange={(e) => apply({ [d.key]: e.target.value || undefined })} aria-label={d.label} options={[{ value: '', label: d.label }, ...d.options.map((o) => ({ value: o, label: o }))]} />
                                    ))}
                                </div>
                            )}
                            {needs.length > 0 && (
                                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', fontSize: 'var(--fs-sm)' }}>
                                    <button type="button" onClick={() => setNeeds(allOn ? [] : needs.map((n) => n.slug))} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', border: 0, background: 'transparent', padding: 0, cursor: 'pointer', fontWeight: 'var(--fw-semibold)', color: 'var(--c-primary)' }}>
                                        <Icon name={allOn ? 'check_box' : selected.length ? 'indeterminate_check_box' : 'check_box_outline_blank'} size="var(--icon-sm)" />
                                        {allOn ? 'Tout désélectionner' : `Tout sélectionner (${needs.length})`}
                                    </button>
                                    <span style={{ flex: 1 }} />
                                    <span style={{ color: 'var(--c-muted)' }}>{selected.length} sélectionné(s)</span>
                                </div>
                            )}
                            {needs.map((n) => {
                                const { bg, fg } = toneColors(n.urgency.tone);
                                return (
                                    <button key={n.id} type="button" onClick={() => toggleNeed(n.slug)} className="och-hover-card" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3)', borderRadius: 'var(--radius-xl)', cursor: 'pointer', textAlign: 'left', border: `1.5px solid ${n.on ? 'var(--c-primary-400)' : 'var(--c-line)'}`, background: n.on ? 'var(--c-primary-50)' : 'var(--c-surface)' }}>
                                        <span role="checkbox" aria-checked={n.on} style={{ width: 20, height: 20, borderRadius: 'var(--radius-sm)', flex: 'none', display: 'grid', placeItems: 'center', border: `1.5px solid ${n.on ? 'var(--c-primary)' : 'var(--c-line-hover)'}`, background: n.on ? 'var(--c-primary)' : 'var(--c-surface)' }}>
                                            {n.on && <Icon name="check" size="var(--icon-xs)" color="var(--c-surface)" />}
                                        </span>
                                        <span style={{ width: 52, height: 52, borderRadius: 'var(--radius-md)', flex: 'none', display: 'grid', placeItems: 'center', background: 'var(--c-primary-100)' }}>
                                            <Icon name="apartment" size="var(--icon-md)" color="var(--c-primary-600)" />
                                        </span>
                                        <span style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column', gap: 2, fontSize: 'var(--fs-sm)' }}>
                                            <b style={{ fontSize: 'var(--fs-base)' }}>{n.name}</b>
                                            <span style={{ color: 'var(--c-ink-2)' }}>{n.line}</span>
                                            <span style={{ color: 'var(--c-muted)' }}>
                                                {n.location} · Démarrage {formatDate(n.startsOn)}
                                            </span>
                                            <span style={{ color: 'var(--c-muted)' }}>{n.meta}</span>
                                            <span style={{ color: 'var(--c-primary-600)', fontWeight: 'var(--fw-semibold)' }}>{n.auto}</span>
                                        </span>
                                        <span style={{ alignSelf: 'flex-end', padding: '2px var(--space-2_5)', borderRadius: 'var(--radius-sm)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', background: bg, color: fg }}>{n.urgency.label}</span>
                                    </button>
                                );
                            })}
                            {!needs.length && <StateBlock kind="empty" title="Aucun besoin à matcher" text="Aucun besoin validé, publié et ouvert ne correspond à la recherche." />}
                        </div>
                    )}
                </section>

                <section data-component="RecommendedProfiles" style={{ ...panel, flex: listOpen ? '2 1 560px' : '1 1 100%', padding: 'var(--space-5)' }}>
                    <div style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)', flexWrap: 'wrap' }}>
                        <Icon name="group" size="var(--icon-md)" color="var(--c-primary)" />
                        <div style={{ flex: 1, minWidth: 220 }}>
                            <h2 style={{ margin: 0, fontSize: 'var(--fs-2xl)', fontWeight: 'var(--fw-bold)' }}>Profils recommandés</h2>
                            <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>
                                {first
                                    ? `${selected.length > 1 ? `${selected.length} besoins` : first.site.name} · ${groups.length} poste(s) · ${sums.required} ouvrier(s) recherché(s) · profils du même métier proposés automatiquement, un ouvrier par poste`
                                    : 'Aucun besoin sélectionné · cochez un ou plusieurs besoins'}
                            </div>
                        </div>
                        <div style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'center', flexWrap: 'wrap' }}>
                            <Button label="Tous les résultats" size="sm" variant="secondary" onClick={() => first && router.visit(`/matching/besoins/${first.id}/resultats`)} />
                            <div style={{ minWidth: 210 }}>
                                <SelectInput value={sort} onChange={(e) => apply({ tri: e.target.value === 'pertinence' ? undefined : e.target.value })} aria-label="Trier les profils" options={Object.entries(sorts).map(([value, label]) => ({ value, label: `Trier : ${label.toLowerCase()}` }))} />
                            </div>
                        </div>
                    </div>

                    {selected.length > 0 && (
                        <div style={{ position: 'sticky', top: 'var(--space-2)', zIndex: 5, display: 'flex', alignItems: 'center', gap: 'var(--space-4)', flexWrap: 'wrap', marginBottom: 'var(--space-4)', padding: 'var(--space-3) var(--space-4)', border: '1px solid var(--c-primary-200)', borderRadius: 'var(--radius-xl)', background: 'var(--c-primary-50)', boxShadow: 'var(--shadow-md)' }}>
                            {summary.map(([icon, color, value, text]) => (
                                <span key={text} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-sm)' }}>
                                    <Icon name={icon} size="var(--icon-sm)" color={color} />
                                    <b style={{ fontSize: 'var(--fs-md)' }}>{value}</b>
                                    {text}
                                </span>
                            ))}
                            <span style={{ flex: 1 }} />
                            <Button label={`Valider les profils matchés (${sums.selected})`} iconRight="arrow_forward" variant="primary" onClick={() => actions.validateAll(groups)} />
                        </div>
                    )}

                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                        {groups.map((g, gi) => (
                            <div key={g.offer.id} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                                {g.head && (
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap', paddingTop: gi ? 'var(--space-3)' : 0 }}>
                                        <span style={{ width: 32, height: 32, borderRadius: 'var(--radius-md)', display: 'grid', placeItems: 'center', background: 'var(--c-primary-100)' }}>
                                            <Icon name="apartment" size="var(--icon-sm)" color="var(--c-primary-600)" />
                                        </span>
                                        <div style={{ flex: 1, minWidth: 200 }}>
                                            <div style={{ fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-lg)' }}>{g.head.title}</div>
                                            <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{g.head.sub}</div>
                                        </div>
                                        <span style={{ padding: '2px var(--space-2_5)', borderRadius: 'var(--radius-pill)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', background: g.head.complete ? 'var(--c-success-bg)' : 'var(--c-warning-bg)', color: g.head.complete ? 'var(--c-success-fg)' : 'var(--c-warning-fg)' }}>{g.head.badge}</span>
                                        {g.head.selected > 0 && selected.length > 1 && <Button label={`Valider ce besoin (${g.head.selected})`} icon="done_all" size="sm" variant="primary" onClick={() => actions.validateAll(groups.filter((x) => g.head!.offers.includes(x.offer.id)))} />}
                                    </div>
                                )}
                                <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', padding: 'var(--space-4)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', background: 'var(--c-surface-3)' }}>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
                                        <Icon name="engineering" size="var(--icon-md)" color="var(--c-primary)" />
                                        <div style={{ flex: 1, minWidth: 180 }}>
                                            <div style={{ fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-md)' }}>
                                                {g.offer.trade} · {g.offer.specialty}
                                            </div>
                                            <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                                {g.offer.rest} ouvrier{g.offer.rest > 1 ? 's' : ''} recherché{g.offer.rest > 1 ? 's' : ''} · {g.chosen} choisi{g.chosen > 1 ? 's' : ''} · {g.offer.reference}
                                            </div>
                                        </div>
                                        <Button label={g.altOn ? 'Masquer les alternatives' : `Alternatives (${g.alternatives})`} icon={g.altOn ? 'expand_less' : 'swap_horiz'} size="sm" variant="secondary" onClick={() => toggleAlt(g.offer.id)} />
                                        {g.chosen > 0 && <Button label={`Valider ${g.offer.trade.toLowerCase()} (${g.chosen})`} size="sm" variant="primary" onClick={() => actions.validate(g.offer, g.selection)} />}
                                    </div>
                                    {g.altOn && g.chosen >= g.offer.rest && (
                                        <div role="status" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-2) var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-success-bg)', color: 'var(--c-success-fg)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)' }}>
                                            <Icon name="task_alt" size="var(--icon-sm)" />
                                            Poste complet ({g.chosen} / {g.offer.rest}) : désélectionnez un ouvrier pour en choisir un autre.
                                        </div>
                                    )}
                                    <div style={{ fontSize: 'var(--fs-sm)' }}>
                                        <b>{g.label}</b> ·{' '}
                                        <span style={{ color: g.chosen >= g.offer.rest ? 'var(--c-success-fg)' : g.picks.length ? 'var(--c-muted)' : 'var(--c-danger-fg)' }}>
                                            {g.chosen >= g.offer.rest ? 'Sélection complète' : `Choisissez ${g.offer.rest - g.chosen} profil${g.offer.rest - g.chosen > 1 ? 's' : ''}`}
                                        </span>
                                    </div>
                                    {g.picks.map((p) => (
                                        <ProfileCard key={p.key} p={p} locked={!p.selected && g.chosen >= g.offer.rest} onToggle={() => onToggle(p, g.offer.rest, g.chosen, g.offer.trade)} onView={() => actions.drawer(p)} />
                                    ))}
                                    {!g.picks.length && <StateBlock kind="empty" title="Aucun profil compatible" text="Consultez les profils exclus de ce besoin." actionLabel="Profils exclus" onAction={() => router.visit(`/matching/besoins/${g.offer.id}/exclus`)} />}
                                </div>
                            </div>
                        ))}
                        {!groups.length && <StateBlock kind="empty" title="Aucun profil recommandé" text="Sélectionnez un ou plusieurs besoins à pourvoir : les ouvriers adéquats s’affichent automatiquement." />}
                    </div>
                </section>
            </div>

            {actions.dialog}
        </>
    );
}
