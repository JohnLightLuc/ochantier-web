import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { formatDate } from '@/lib/format';
import type { SiteCard, SiteEvent } from '@/types/sites';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const open = (slug: string) => router.visit(`/chantiers/${slug}`);

const panel = { background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-4)', minWidth: 0 } as const;

/** Couleur d’un ton de statut (g, a, r…) pour les pastilles. */
const TONE_COLORS: Record<string, string> = { g: 'var(--c-success)', a: 'var(--c-warning)', r: 'var(--c-danger)', b: 'var(--c-info)', p: 'var(--c-purple)', o: 'var(--c-orange)', n: 'var(--c-neutral)' };

function Legend({ items }: { items: [string, string][] }) {
    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-4)', marginTop: 'var(--space-3)' }}>
            {items.map(([label, color]) => (
                <span key={label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-sm)' }}>
                    <span style={{ width: 10, height: 10, borderRadius: 'var(--radius-round)', background: color }} />
                    {label}
                </span>
            ))}
        </div>
    );
}

/** Vue Cartes : une vignette par chantier avec avancement et alerte principale. */
export function SiteCards({ cards }: { cards: SiteCard[] }) {
    if (!cards.length) return <StateBlock kind="empty" title="Aucun chantier" text="Aucun chantier ne correspond à ce filtre." />;

    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,300px),1fr))', gap: 'var(--space-4)' }}>
            {cards.map((c) => (
                <button
                    key={c.id}
                    type="button"
                    onClick={() => open(c.slug)}
                    className="och-hover-card"
                    style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', padding: '0 0 var(--space-4)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', background: 'var(--c-surface)', cursor: 'pointer', textAlign: 'left', overflow: 'hidden', minWidth: 0 }}
                >
                    <span style={{ display: 'grid', placeItems: 'center', height: 120, background: 'repeating-linear-gradient(135deg,var(--c-surface-3) 0 12px,var(--c-surface-2) 12px 24px)', fontFamily: 'ui-monospace,Menlo,monospace', fontSize: 'var(--fs-xs)', color: 'var(--c-muted)' }}>photo du chantier</span>
                    <span style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1_5)', padding: '0 var(--space-4)' }}>
                        <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                            <b style={{ flex: 1, minWidth: 0, fontSize: 'var(--fs-lg)', color: 'var(--c-ink)' }}>{c.name}</b>
                            <StatusBadge label={c.status.label} tone={c.status.tone} />
                        </span>
                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                            {c.promoter} · {c.commune}
                        </span>
                        <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>
                            {formatDate(c.startsOn)} → {formatDate(c.endsOn)} · {c.assigned} / {c.required} ouvriers
                        </span>
                        <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                            <span style={{ flex: 1, height: 7, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden' }}>
                                <span style={{ display: 'block', height: '100%', width: `${c.progress}%`, background: c.progress < 50 ? 'var(--c-accent-600)' : 'var(--c-primary-500)' }} />
                            </span>
                            <b style={{ fontSize: 'var(--fs-sm)' }}>{c.progress} %</b>
                        </span>
                        <span style={{ fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', color: c.alertTone === 'muted' ? 'var(--c-muted)' : `var(--c-${c.alertTone}-fg)` }}>{c.alertText}</span>
                    </span>
                </button>
            ))}
        </div>
    );
}

const EVENT_KINDS: Record<SiteEvent['kind'], [string, string]> = {
    start: ['Début', 'var(--c-success)'],
    end: ['Fin prévue / clôture', 'var(--c-purple)'],
    milestone: ['Jalon', 'var(--c-info)'],
    payment: ['Paiement attendu', 'var(--c-warning)'],
    urgent: ['Besoin urgent', 'var(--c-danger)'],
    replacement: ['Remplacement', 'var(--c-orange)'],
};

const pad = (n: number) => String(n).padStart(2, '0');

/** Vue Calendrier : échéances du mois (débuts, fins, jalons, paiements, besoins urgents, remplacements). */
export function SiteCalendar({ events, referenceDate }: { events: SiteEvent[]; referenceDate: string }) {
    const [month, setMonth] = useState(referenceDate.slice(0, 7));
    const [year, m] = month.split('-').map(Number);
    const first = new Date(year, m - 1, 1);
    const offset = (first.getDay() + 6) % 7;
    const days = new Date(year, m, 0).getDate();
    const title = first.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
    const move = (n: number) => {
        const d = new Date(year, m - 1 + n, 1);
        setMonth(`${d.getFullYear()}-${pad(d.getMonth() + 1)}`);
    };
    const byDay = events.reduce<Record<string, SiteEvent[]>>((acc, e) => {
        if (e.date.startsWith(month)) (acc[e.date] ??= []).push(e);
        return acc;
    }, {});

    return (
        <div style={panel} data-component="SiteCalendar">
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                <Button icon="chevron_left" size="sm" aria-label="Mois précédent" onClick={() => move(-1)} />
                <b style={{ fontSize: 'var(--fs-xl)', minWidth: 170, textAlign: 'center', textTransform: 'capitalize' }}>{title}</b>
                <Button icon="chevron_right" size="sm" aria-label="Mois suivant" onClick={() => move(1)} />
            </div>
            <div style={{ overflowX: 'auto' }}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,minmax(96px,1fr))', gap: 'var(--space-1_5)', minWidth: 700 }}>
                    {['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'].map((d) => (
                        <div key={d} style={{ fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-bold)', color: 'var(--c-muted)', textTransform: 'uppercase', textAlign: 'center' }}>
                            {d}
                        </div>
                    ))}
                    {Array.from({ length: offset }, (_, i) => (
                        <div key={`empty-${i}`} />
                    ))}
                    {Array.from({ length: days }, (_, i) => {
                        const iso = `${month}-${pad(i + 1)}`;
                        const list = byDay[iso] ?? [];
                        return (
                            <div key={iso} style={{ minHeight: 96, padding: 'var(--space-1_5)', borderRadius: 'var(--radius-md)', border: `1.5px solid ${iso === referenceDate ? 'var(--c-primary)' : 'var(--c-line-soft)'}`, display: 'flex', flexDirection: 'column', gap: 3, minWidth: 0 }}>
                                <b style={{ fontSize: 'var(--fs-sm)' }}>{i + 1}</b>
                                {list.slice(0, 3).map((e, k) => (
                                    <button
                                        key={k}
                                        type="button"
                                        title={e.label}
                                        onClick={() => open(e.slug)}
                                        style={{ display: 'flex', alignItems: 'center', gap: 4, border: 0, background: 'var(--c-surface-2)', borderRadius: 'var(--radius-xs)', padding: '2px 4px', cursor: 'pointer', fontSize: 'var(--fs-2xs)', textAlign: 'left', minWidth: 0 }}
                                    >
                                        <span style={{ width: 6, height: 6, flex: 'none', borderRadius: 'var(--radius-round)', background: EVENT_KINDS[e.kind][1] }} />
                                        <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{e.label}</span>
                                    </button>
                                ))}
                                {list.length > 3 && <span style={{ fontSize: 'var(--fs-2xs)', color: 'var(--c-muted)' }}>+ {list.length - 3} autre(s)</span>}
                            </div>
                        );
                    })}
                </div>
            </div>
            <Legend items={Object.values(EVENT_KINDS)} />
        </div>
    );
}

/** Emprise de la carte schématique : latitude min, max, longitude min, max (district d’Abidjan). */
const BOUNDS = [5.18, 5.52, -4.3, -3.7] as const;

const inside = (lat: number, lng: number) => lat >= BOUNDS[0] && lat <= BOUNDS[1] && lng >= BOUNDS[2] && lng <= BOUNDS[3];
const left = (lng: number) => `${((lng - BOUNDS[2]) / (BOUNDS[3] - BOUNDS[2])) * 100}%`;
const top = (lat: number) => `${((BOUNDS[1] - lat) / (BOUNDS[1] - BOUNDS[0])) * 100}%`;

type MapProps = { cards: SiteCard[]; communes: Record<string, [number, number]>; onZone: (commune: string) => void };

/** Vue Carte géographique : positions approximatives, regroupement par commune, chantiers hors district. */
export function SiteMap({ cards, communes, onZone }: MapProps) {
    const [selected, setSelected] = useState<string | null>(null);
    const sel = cards.find((c) => c.slug === selected);
    const onMap = cards.filter((c) => inside(c.latitude, c.longitude));
    const outside = cards.filter((c) => !inside(c.latitude, c.longitude));
    const zones = [...new Set(cards.map((c) => c.commune))].map((z) => [z, cards.filter((c) => c.commune === z).length] as const).sort((a, b) => b[1] - a[1]);

    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-4)' }}>
            <div style={{ ...panel, flex: '2 1 560px' }} data-component="SiteMap">
                <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginBottom: 'var(--space-2)' }}>Carte schématique du district d’Abidjan · positions approximatives</div>
                <div style={{ position: 'relative', height: 440, borderRadius: 'var(--radius-xl)', background: 'var(--c-info-bg)', border: '1px solid var(--c-line)', overflow: 'hidden' }}>
                    {Object.entries(communes)
                        .filter(([, [lat, lng]]) => inside(lat, lng))
                        .map(([name, [lat, lng]]) => (
                            <span key={name} style={{ position: 'absolute', left: left(lng), top: top(lat), transform: 'translate(-50%,-50%)', fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-semibold)', color: 'var(--c-info-fg)', opacity: 0.7, pointerEvents: 'none' }}>
                                {name}
                            </span>
                        ))}
                    {onMap.map((c) => (
                        <button
                            key={c.id}
                            type="button"
                            title={c.name}
                            aria-label={c.name}
                            onClick={() => setSelected(c.slug)}
                            style={{ position: 'absolute', left: left(c.longitude), top: top(c.latitude), width: 18, height: 18, margin: '-9px 0 0 -9px', padding: 0, borderRadius: 'var(--radius-round)', background: TONE_COLORS[c.status.tone] ?? 'var(--c-neutral)', border: '3px solid var(--c-surface)', boxShadow: selected === c.slug ? '0 0 0 4px var(--c-primary-200)' : 'var(--shadow-xs)', cursor: 'pointer' }}
                        />
                    ))}
                    {sel && (
                        <div style={{ position: 'absolute', left: 'var(--space-3)', bottom: 'var(--space-3)', right: 'var(--space-3)', maxWidth: 420, display: 'flex', flexDirection: 'column', gap: 'var(--space-1_5)', padding: 'var(--space-3)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', boxShadow: 'var(--shadow-popover)' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                                <b style={{ flex: 1 }}>{sel.name}</b>
                                <StatusBadge label={sel.status.label} tone={sel.status.tone} />
                                <button type="button" onClick={() => setSelected(null)} aria-label="Fermer" style={{ border: 0, background: 'transparent', cursor: 'pointer', display: 'grid', placeItems: 'center', color: 'var(--c-muted)' }}>
                                    <Icon name="close" size="var(--icon-sm)" />
                                </button>
                            </div>
                            <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                {sel.reference} · {sel.promoter} · {sel.commune}
                            </span>
                            <span style={{ fontSize: 'var(--fs-sm)' }}>
                                {sel.progress} % · {sel.assigned} / {sel.required} ouvriers · {formatDate(sel.startsOn)} → {formatDate(sel.endsOn)}
                            </span>
                            <div>
                                <Button label="Ouvrir la fiche" icon="open_in_new" size="sm" variant="primary" onClick={() => open(sel.slug)} />
                            </div>
                        </div>
                    )}
                </div>
                <Legend items={[['En cours', TONE_COLORS.g], ['Prêt à démarrer · planifié', TONE_COLORS.b], ['Recrutement en cours', TONE_COLORS.p], ['En attente de clôture', TONE_COLORS.a], ['En retard · suspendu', TONE_COLORS.r], ['Brouillon · clôturé', TONE_COLORS.n]]} />
            </div>
            <div style={{ ...panel, flex: '1 1 280px', display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                <h3 style={{ margin: 0, fontSize: 'var(--fs-xl)' }}>Regroupement par zone</h3>
                {zones.map(([zone, count]) => (
                    <button key={zone} type="button" onClick={() => onZone(zone)} className="och-hover-soft" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-2) var(--space-2_5)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-md)', background: 'var(--c-surface)', cursor: 'pointer', textAlign: 'left' }}>
                        <Icon name="location_on" size="var(--icon-sm)" color="var(--c-primary-600)" />
                        <span style={{ flex: 1 }}>{zone}</span>
                        <b>{count}</b>
                    </button>
                ))}
                {outside.length > 0 && (
                    <>
                        <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', marginTop: 'var(--space-2)' }}>Hors du district d’Abidjan</div>
                        {outside.map((c) => (
                            <button key={c.id} type="button" onClick={() => open(c.slug)} style={{ border: 0, background: 'transparent', padding: 0, textAlign: 'left', color: 'var(--c-primary-600)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer', fontSize: 'var(--fs-sm)' }}>
                                {c.name} · {c.commune}
                            </button>
                        ))}
                    </>
                )}
                {!cards.length && <span style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Aucun chantier ne correspond à ce filtre.</span>}
            </div>
        </div>
    );
}
