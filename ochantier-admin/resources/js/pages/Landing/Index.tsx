import SiteBlock from '@/components/landing/SiteBlock';
import LandingShell from '@/components/landing/LandingShell';
import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { RowActions, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import StatCard from '@/components/ui/StatCard';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import type { LandingApplicationRow, LandingIndexProps, LandingList, LandingMessageRow, NewsletterRow } from '@/types/landing';
import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';

const APPLICATION_STATUSES = [
    { value: 'nouvelle', label: 'Nouvelle' },
    { value: 'en_verification', label: 'En vérification' },
    { value: 'convertie', label: 'Convertie' },
    { value: 'refusee', label: 'Refusée' },
];

const MESSAGE_STATUSES = [
    { value: 'nouveau', label: 'Nouveau' },
    { value: 'en_cours', label: 'En cours' },
    { value: 'traite', label: 'Traité' },
];

/**
 * Landing Page : le site O’CHANTIER et ses demandes. Vue d’ensemble (site public, structure, demandes à traiter),
 * candidatures « Devenir ouvrier », messages de contact et inscrits à la newsletter, chaque onglet selon les droits.
 */
export default function LandingIndex({ tab, tabs, draft, site, overview, filters, list }: LandingIndexProps) {
    const base = tab ? `/landing-page/${tab}` : '/landing-page';
    const apply = (changes: LandingIndexProps['filters'], keepPage = false) => {
        const next = { ...filters, ...changes, ...(keepPage ? {} : { page: undefined }) };
        router.get(base, Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="Landing Page" />

            <LandingShell tab={tab} tabs={tabs} draft={draft}>
                {tab === '' && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
                        {site && <SiteBlock site={site} canEdit={!!draft?.canEdit} />}
                        <Overview overview={overview} />
                    </div>
                )}

                {tab !== '' && list && (
                    <>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                            <SearchInput value={filters.q ?? ''} placeholder={tab === 'newsletter' ? 'Rechercher une adresse e-mail…' : 'Rechercher un nom, une référence, un téléphone…'} onChange={(q) => apply({ q })} />
                            {tab === 'candidatures' && <FilterSelect label="Statut" value={filters.statut ?? ''} options={APPLICATION_STATUSES} onChange={(statut) => apply({ statut })} />}
                            {tab === 'candidatures' && list.trades && <FilterSelect label="Métier" value={filters.metier ?? ''} options={list.trades.map((t) => ({ value: t, label: t }))} onChange={(metier) => apply({ metier })} />}
                            {tab === 'messages' && <FilterSelect label="Statut" value={filters.statut ?? ''} options={MESSAGE_STATUSES} onChange={(statut) => apply({ statut })} />}
                            {tab === 'messages' && <Button label={filters.retard ? 'Tous les messages' : `En retard (+${list.responseHours ?? 24} h)`} icon="schedule" size="sm" variant={filters.retard ? 'primary' : 'secondary'} onClick={() => apply({ retard: filters.retard ? undefined : '1' })} />}
                            {tab === 'newsletter' && <Button label="Exporter (CSV)" icon="download" size="sm" variant="secondary" onClick={() => (window.location.href = '/landing-page/newsletter/export')} />}
                            <span style={{ marginLeft: 'auto', alignSelf: 'center', fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}>
                                <b style={{ color: 'var(--c-ink)' }}>{list.total}</b> {tab === 'newsletter' ? 'inscrit(s)' : 'demande(s)'}
                            </span>
                        </div>

                        {tab === 'candidatures' && <Applications list={list as LandingList<LandingApplicationRow>} />}
                        {tab === 'messages' && <Messages list={list as LandingList<LandingMessageRow>} />}
                        {tab === 'newsletter' && <Newsletter list={list as LandingList<NewsletterRow>} />}

                        <Pagination page={list.currentPage} lastPage={list.lastPage} from={list.from} to={list.to} total={list.total} onPage={(page) => apply({ page: String(page) }, true)} />
                    </>
                )}
            </LandingShell>
        </>
    );
}

/** Vue d’ensemble : demandes à traiter et accès rapides. */
function Overview({ overview: o }: { overview: LandingIndexProps['overview'] }) {
    const tile = (key: string, label: string, value: number, icon: string, tone: 'g' | 'a' | 'b' | 'r', href: string, hint?: string, highlight = false) => (
        <Link key={key} href={href} className="och-hover-lift" style={{ display: 'block', borderRadius: 'var(--radius-2xl)', color: 'inherit', textDecoration: 'none' }}>
            <StatCard label={label} value={String(value)} icon={icon} tone={tone} hint={hint} highlight={highlight} />
        </Link>
    );
    const tiles: ReactNode[] = [];

    if (o.applications) {
        tiles.push(tile('new', 'Nouvelles candidatures', o.applications.nouvelle, 'engineering', 'b', '/landing-page/candidatures?statut=nouvelle', 'Formulaire « Devenir ouvrier »', o.applications.nouvelle > 0));
        tiles.push(tile('review', 'Candidatures en vérification', o.applications.en_verification, 'fact_check', 'a', '/landing-page/candidatures?statut=en_verification', `${o.applications.convertie} convertie(s) en profil ouvrier`));
    }

    if (o.messages) {
        tiles.push(tile('msg', 'Messages à traiter', o.messages.nouveau + o.messages.en_cours, 'mail', 'b', '/landing-page/messages', `Réponse annoncée sous ${o.messages.response_hours} h`));
        tiles.push(tile('late', 'Messages en retard', o.messages.late, 'schedule', 'r', '/landing-page/messages?retard=1', undefined, o.messages.late > 0));
    }

    if (o.newsletter) {
        tiles.push(tile('nl', 'Inscrits à la newsletter', o.newsletter.active, 'campaign', 'g', '/landing-page/newsletter', `${o.newsletter.month} ce mois-ci`));
    }

    return tiles.length ? (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))', gap: 'var(--space-4)' }}>{tiles}</div>
    ) : (
        <StateBlock kind="nodata" icon="web" title="Aucune demande visible" text="Les candidatures, messages et inscriptions du site demandent chacun leur droit d’accès." />
    );
}

function Table({ columns, rows, empty }: { columns: string[]; rows: ReactNode; empty: ReactNode | null }) {
    return (
        <>
            <TableFrame minWidth={900}>
                <thead>
                    <tr style={{ background: 'var(--c-surface-3)' }}>
                        {columns.map((c) => (
                            <th key={c} style={thStyle}>
                                {c}
                            </th>
                        ))}
                        <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                    </tr>
                </thead>
                <tbody>{rows}</tbody>
            </TableFrame>
            {empty}
        </>
    );
}

function Applications({ list }: { list: LandingList<LandingApplicationRow> }) {
    return (
        <Table
            columns={['Candidat', 'Métier · expérience', 'Commune', 'Disponibilité', 'Statut', 'Reçue le']}
            empty={list.data.length ? null : <StateBlock kind="nodata" icon="engineering" title="Aucune candidature" text="Les candidatures du formulaire « Devenir ouvrier » apparaîtront ici." />}
            rows={list.data.map((a) => {
                const open = () => router.visit(`/landing-page/candidatures/${a.id}`);
                return (
                    <tr key={a.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: a.status.value === 'nouvelle' ? 'var(--c-primary-50)' : 'var(--c-surface)' }}>
                        <td style={tdStyle}>
                            <TextCell strong>{a.name}</TextCell>
                            <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                {a.reference} · {a.phone}
                            </span>
                            {a.duplicates > 0 && (
                                <div style={{ marginTop: 'var(--space-1)' }}>
                                    <StatusBadge label="Doublon possible" tone="o" icon="content_copy" />
                                </div>
                            )}
                        </td>
                        <td style={tdStyle}>
                            <TextCell>{a.trade}</TextCell>
                            <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{a.experience}</span>
                        </td>
                        <td style={tdStyle}>
                            <TextCell>{a.commune}</TextCell>
                        </td>
                        <td style={tdStyle}>
                            <TextCell>{a.availability}</TextCell>
                            {a.targetSite && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>pour {a.targetSite}</span>}
                        </td>
                        <td style={tdStyle}>
                            <StatusBadge label={a.status.label} tone={a.status.tone} />
                        </td>
                        <td style={tdStyle}>
                            <TextCell muted>{a.receivedAt ?? '—'}</TextCell>
                        </td>
                        <RowActions actions={[{ icon: 'visibility', label: 'Voir la candidature', onClick: open }]} />
                    </tr>
                );
            })}
        />
    );
}

function Messages({ list }: { list: LandingList<LandingMessageRow> }) {
    return (
        <Table
            columns={['Expéditeur', 'Objet', 'Profil', 'Statut', 'Reçu le']}
            empty={list.data.length ? null : <StateBlock kind="nodata" icon="mail" title="Aucun message" text="Les messages du formulaire « Nous contacter » apparaîtront ici." />}
            rows={list.data.map((m) => {
                const open = () => router.visit(`/landing-page/messages/${m.id}`);
                return (
                    <tr key={m.id} onClick={open} className="och-hover-soft" style={{ cursor: 'pointer', background: m.status.value === 'nouveau' ? 'var(--c-primary-50)' : 'var(--c-surface)' }}>
                        <td style={tdStyle}>
                            <TextCell strong>{m.name}</TextCell>
                            <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{[m.reference, m.company].filter(Boolean).join(' · ')}</span>
                        </td>
                        <td style={tdStyle}>
                            <TextCell>{m.subject}</TextCell>
                        </td>
                        <td style={tdStyle}>
                            <TextCell>{m.profile}</TextCell>
                        </td>
                        <td style={tdStyle}>
                            <span style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1)' }}>
                                <StatusBadge label={m.status.label} tone={m.status.tone} />
                                {m.late && <StatusBadge label="En retard" tone="r" icon="schedule" />}
                            </span>
                            {m.handledBy && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>par {m.handledBy}</span>}
                        </td>
                        <td style={tdStyle}>
                            <TextCell muted>{m.receivedAt ?? '—'}</TextCell>
                        </td>
                        <RowActions actions={[{ icon: 'visibility', label: 'Voir le message', onClick: open }]} />
                    </tr>
                );
            })}
        />
    );
}

function Newsletter({ list }: { list: LandingList<NewsletterRow> }) {
    return (
        <Table
            columns={['Adresse e-mail', 'Inscrit le']}
            empty={list.data.length ? null : <StateBlock kind="nodata" icon="campaign" title="Aucun inscrit" text="Les inscriptions à la lettre d’information du site apparaîtront ici." />}
            rows={list.data.map((s) => (
                <tr key={s.id} style={{ background: 'var(--c-surface)' }}>
                    <td style={tdStyle}>
                        <TextCell strong>{s.email}</TextCell>
                    </td>
                    <td style={tdStyle}>
                        <TextCell muted>{s.subscribedAt ?? '—'}</TextCell>
                    </td>
                    <RowActions actions={[{ icon: 'content_copy', label: 'Copier l’adresse', onClick: () => navigator.clipboard?.writeText(s.email).catch(() => undefined) }]} />
                </tr>
            ))}
        />
    );
}
