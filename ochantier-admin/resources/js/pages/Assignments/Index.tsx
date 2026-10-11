import { fcfa, useAssignmentActions } from '@/components/assignments/useAssignmentActions';
import { SearchInput } from '@/components/list/ListToolbar';
import { TableFrame, tdStyle, thStyle } from '@/components/list/Table';
import Dialog from '@/components/overlays/Dialog';
import Button from '@/components/ui/Button';
import Field, { SelectInput } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { AnyTone } from '@/types';
import type { AssignmentsIndexProps, PromoterOfferRow } from '@/types/assignments';
import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

/** Puce de filtre avec compteur. */
function Chip({ label, count, on, onClick }: { label: string; count: number; on: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            aria-pressed={on}
            onClick={onClick}
            className="och-hover-line"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1_5)', height: 'var(--control-sm)', padding: '0 var(--space-2) 0 var(--space-3)', border: `1px solid ${on ? 'var(--c-primary-400)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-pill)', background: on ? 'var(--c-primary-50)' : 'var(--c-surface)', color: on ? 'var(--c-primary)' : 'var(--c-ink-2)', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', cursor: 'pointer' }}
        >
            {label}
            <span style={{ minWidth: 22, height: 20, padding: '0 6px', borderRadius: 'var(--radius-pill)', background: on ? 'var(--c-primary)' : 'var(--c-neutral-bg)', color: on ? 'var(--c-on-dark)' : 'var(--c-neutral-fg)', display: 'inline-grid', placeItems: 'center', fontSize: 'var(--fs-xs)' }}>{count}</span>
        </button>
    );
}

/** Barre de progression fine. */
function Bar({ percent }: { percent: number }) {
    return (
        <span style={{ flex: 1, minWidth: 60, height: 6, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden' }}>
            <span style={{ display: 'block', height: '100%', width: `${Math.min(100, percent)}%`, background: percent >= 100 ? 'var(--c-primary-500)' : 'var(--c-accent-600)' }} />
        </span>
    );
}

type SectionProps = { component: string; icon: string; title: string; text: string; count: string; chips: ReactNode; search: ReactNode; children: ReactNode };

/** Section de liste : titre, explication, puces de filtre et recherche. */
function Section({ component, icon, title, text, count, chips, search, children }: SectionProps) {
    return (
        <section data-component={component} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', padding: 'var(--space-5)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', minWidth: 0 }}>
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
                <Icon name={icon} size="var(--icon-md)" color="var(--c-primary)" />
                <div style={{ flex: 1, minWidth: 220 }}>
                    <h2 style={{ margin: 0, fontSize: 'var(--fs-lg)', fontWeight: 'var(--fw-bold)' }}>{title}</h2>
                    <p style={{ margin: 'var(--space-1) 0 0', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{text}</p>
                </div>
                <span style={{ fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', color: 'var(--c-ink-2)' }}>{count}</span>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
                <div role="group" aria-label={`Filtrer : ${title}`} style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)', flex: '3 1 420px' }}>
                    {chips}
                </div>
                {search}
            </div>
            {children}
        </section>
    );
}

/** Choix d’un poste ouvert avant d’ajouter un ouvrier à l’affectation. */
function CreateDialog({ posts, onClose }: { posts: AssignmentsIndexProps['openPosts']; onClose: () => void }) {
    const [value, setValue] = useState(posts[0]?.value ?? '');

    return (
        <Dialog title="Créer une affectation" body="Choisissez le besoin et le poste à pourvoir, puis l’ouvrier à affecter." icon="add_circle" okLabel="Continuer" onClose={onClose} onSubmit={() => value && router.visit(`/affectations/besoins/${value.split(':')[0]}?ajout=${encodeURIComponent(value)}`)}>
            <Field label="Poste à pourvoir" required>
                <SelectInput value={value} onChange={(e) => setValue(e.target.value)} placeholder="" options={posts} />
            </Field>
        </Dialog>
    );
}

/** Affectations & Offres : besoins en cours d’affectation et offres des promoteurs, filtrés et recherchés sur place. */
export default function AssignmentsIndex({ kpis, needs, offers, needFilters, offerFilters, filters, options, openPosts, refreshedAt }: AssignmentsIndexProps) {
    const ui = useAdminUi();
    const actions = useAssignmentActions(options);
    const [needFilter, setNeedFilter] = useState(filters.besoins);
    const [needQuery, setNeedQuery] = useState(filters.q);
    const [offerFilter, setOfferFilter] = useState(filters.offres);
    const [offerQuery, setOfferQuery] = useState(filters.qo);
    const [creating, setCreating] = useState(false);

    const needMatches = (label: string) => needs.filter((n) => label === 'Tous' || n.filters.includes(label));
    const needRows = needMatches(needFilter).filter((n) => !needQuery.trim() || n.search.includes(needQuery.trim().toLowerCase()));
    const offerMatches = (label: string) => offers.filter((o) => label === 'Toutes' || o.status.label === label);
    const offerRows = offerMatches(offerFilter).filter((o) => !offerQuery.trim() || o.search.includes(offerQuery.trim().toLowerCase()));
    const toContact = needs.reduce((n, r) => n + r.toContact, 0);

    const followNeed = (slug: string) => router.visit(`/affectations/besoins/${slug}`);
    const openOffer = (slug: string) => router.visit(`/affectations/offres/${slug}`);
    const pay = (o: PromoterOfferRow) => actions.payDeposit(`/affectations/offres/${o.slug}/acompte`, `${o.promoter} · acompte de ${fcfa(o.deposit.deposit)} (${o.deposit.rate} % · Paramètres) sur un coût global de ${fcfa(o.deposit.total)}. La demande du promoteur sera validée.`);
    const create = () => (openPosts.length ? setCreating(true) : ui.toast('Aucun poste ouvert à pourvoir.', { tone: 'error' }));

    const actionButton = (label: string, onClick: () => void, primary = false, icon = 'arrow_forward') => (
        <Button label={label} {...(primary ? { icon } : { iconRight: icon })} size="sm" variant={primary ? 'primary' : 'secondary'} onClick={onClick} />
    );

    return (
        <>
            <Head title="Affectations & Offres" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 'var(--space-2)', flexWrap: 'wrap', marginTop: -6 }}>
                <Button label="Exporter" icon="download" variant="secondary" onClick={() => (window.location.href = '/affectations/export')} />
                <Button label="Créer une affectation" icon="add_circle" variant="primary" onClick={create} />
            </div>

            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>
                {kpis.map((k) => (
                    <StatCard key={k.label} label={k.label} value={k.value} icon={k.icon} tone={k.tone as AnyTone} hint={k.hint} highlight={k.highlight} />
                ))}
            </div>

            <Section
                component="BesoinsAffectation"
                icon="event_available"
                title="Besoins en cours d’affectation"
                text={`Ouvriers trouvés par le matching, ajoutés manuellement ou retenus parmi les candidatures · actualisé à ${refreshedAt}. Besoins dont des ouvriers ont été validés depuis le Matching : contactez et validez-les au fur et à mesure que le besoin se comble.`}
                count={`${needRows.length} besoin(s) · ${toContact} ouvrier(s) à contacter`}
                chips={needFilters.map((label) => (
                    <Chip key={label} label={label} count={needMatches(label).length} on={needFilter === label} onClick={() => setNeedFilter(label)} />
                ))}
                search={<SearchInput value={needQuery} placeholder="Référence, chantier, promoteur, métier, ouvrier" onChange={setNeedQuery} debounce={150} />}
            >
                {needRows.length ? (
                    <TableFrame minWidth={1200}>
                        <thead>
                            <tr style={{ background: 'var(--c-surface-3)' }}>
                                {['Besoin', 'Chantier', 'Promoteur', 'Métiers', 'Sélectionnés / recherchés', 'Suivi des ouvriers', 'Début', 'Étape', 'Paiement', ''].map((c, i) => (
                                    <th key={i} style={thStyle}>
                                        {c}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {needRows.map((n) => (
                                <tr key={n.slug} onClick={() => followNeed(n.slug)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                    <td style={{ ...tdStyle, fontWeight: 'var(--fw-bold)', whiteSpace: 'nowrap' }}>{n.reference}</td>
                                    <td style={tdStyle}>
                                        <div style={{ fontWeight: 'var(--fw-semibold)' }}>{n.site}</div>
                                        <div style={{ color: 'var(--c-muted)', fontSize: 'var(--fs-sm)' }}>{n.location}</div>
                                    </td>
                                    <td style={{ ...tdStyle, color: 'var(--c-ink-2)' }}>{n.promoter}</td>
                                    <td style={{ ...tdStyle, color: 'var(--c-ink-2)' }}>{n.trades}</td>
                                    <td style={{ ...tdStyle, minWidth: 160 }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                                            <b>
                                                {n.selected} / {n.need}
                                            </b>
                                            <Bar percent={n.percent} />
                                        </div>
                                        <div style={{ fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', color: n.rest ? 'var(--c-warning-fg)' : 'var(--c-success-fg)' }}>{n.rest ? `${n.rest} manquant(s)` : 'Complet'}</div>
                                    </td>
                                    <td style={{ ...tdStyle, color: 'var(--c-ink-2)' }}>{n.steps}</td>
                                    <td style={{ ...tdStyle, whiteSpace: 'nowrap', color: 'var(--c-ink-2)' }}>{formatDate(n.startsOn)}</td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={n.status.label} tone={n.status.tone} />
                                    </td>
                                    <td style={{ ...tdStyle, color: 'var(--c-ink-2)', whiteSpace: 'nowrap' }}>{n.payment}</td>
                                    <td style={{ ...tdStyle, textAlign: 'right' }} onClick={(e) => e.stopPropagation()}>
                                        {actionButton('Suivre', () => followNeed(n.slug))}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </TableFrame>
                ) : (
                    <StateBlock kind="empty" title="Aucun besoin" text="Aucun besoin ne correspond à ce filtre ou à cette recherche." />
                )}
            </Section>

            <Section
                component="OffresPromoteurs"
                icon="local_offer"
                title="Offres des promoteurs"
                text="Demandes où le promoteur a déjà choisi ses ouvriers : contactez chaque ouvrier, validez ou invalidez-le, remplacez les désistements par matching, le promoteur est ensuite notifié automatiquement pour le paiement de l’acompte."
                count={`${offerRows.length} offre(s) · ${offerMatches('À traiter').length} à traiter`}
                chips={offerFilters.map((label) => (
                    <Chip key={label} label={label} count={offerMatches(label).length} on={offerFilter === label} onClick={() => setOfferFilter(label)} />
                ))}
                search={<SearchInput value={offerQuery} placeholder="Référence, promoteur, chantier, métier, ouvrier" onChange={setOfferQuery} debounce={150} />}
            >
                {offerRows.length ? (
                    <TableFrame minWidth={1080}>
                        <thead>
                            <tr style={{ background: 'var(--c-surface-3)' }}>
                                {['Offre', 'Chantier', 'Poste demandé', 'Traitement des ouvriers', 'Urgence', 'Statut', ''].map((c, i) => (
                                    <th key={i} style={thStyle}>
                                        {c}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {offerRows.map((o) => (
                                <tr key={o.slug} onClick={() => openOffer(o.slug)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)', verticalAlign: 'top' }}>
                                    <td style={{ ...tdStyle, minWidth: 170 }}>
                                        <div style={{ fontWeight: 'var(--fw-bold)', whiteSpace: 'nowrap' }}>{o.reference}</div>
                                        <div style={{ color: 'var(--c-ink-2)', marginTop: 2 }}>{o.promoter}</div>
                                        <div style={{ color: 'var(--c-muted)', fontSize: 'var(--fs-xs)', marginTop: 2 }}>Reçue le {formatDate(o.sentOn)}</div>
                                    </td>
                                    <td style={{ ...tdStyle, minWidth: 190 }}>
                                        <div style={{ fontWeight: 'var(--fw-semibold)' }}>{o.site}</div>
                                        <div style={{ color: 'var(--c-muted)', marginTop: 2, fontSize: 'var(--fs-sm)' }}>{o.location}</div>
                                    </td>
                                    <td style={{ ...tdStyle, minWidth: 170 }}>
                                        <div style={{ fontWeight: 'var(--fw-semibold)' }}>{o.trade}</div>
                                        <div style={{ color: 'var(--c-muted)', marginTop: 2, fontSize: 'var(--fs-sm)' }}>{o.post}</div>
                                    </td>
                                    <td style={{ ...tdStyle, minWidth: 240, maxWidth: 320 }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                                            <Bar percent={o.percent} />
                                            <b style={{ whiteSpace: 'nowrap' }}>
                                                {o.accepted} / {o.total} validé{o.accepted > 1 ? 's' : ''}
                                            </b>
                                        </div>
                                        <div style={{ color: o.refused ? 'var(--c-danger-fg)' : 'var(--c-muted)', fontWeight: 'var(--fw-semibold)', marginTop: 4, fontSize: 'var(--fs-sm)' }}>{o.progress}</div>
                                        <div title={o.profiles} style={{ color: 'var(--c-muted)', fontSize: 'var(--fs-xs)', marginTop: 2, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                            {o.profiles}
                                        </div>
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={o.urgency.label} tone={o.urgency.tone} />
                                    </td>
                                    <td style={tdStyle}>
                                        <StatusBadge label={o.status.label} tone={o.status.tone} />
                                    </td>
                                    <td style={{ ...tdStyle, textAlign: 'right' }} onClick={(e) => e.stopPropagation()}>
                                        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 'var(--space-2)' }}>
                                            {actionButton('Traiter', () => openOffer(o.slug))}
                                            {o.canPay && actionButton('Valider l’acompte', () => pay(o), true, 'payments')}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </TableFrame>
                ) : (
                    <StateBlock kind="empty" title="Aucune offre" text="Aucune offre ne correspond à ce filtre ou à cette recherche." />
                )}
            </Section>

            {creating && <CreateDialog posts={openPosts} onClose={() => setCreating(false)} />}
            {actions.dialog}
        </>
    );
}
