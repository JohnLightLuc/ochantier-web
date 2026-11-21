import LandingShell from '@/components/landing/LandingShell';
import Pagination from '@/components/list/Pagination';
import { RowActions, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { LandingShellProps, LandingVersion } from '@/types/landing';
import { Head, router } from '@inertiajs/react';

type Props = LandingShellProps & {
    list: { data: LandingVersion[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
};

/** Landing Page, onglet Publications : versions publiées du site, de la plus récente, et restauration dans le brouillon. */
export default function LandingPublications({ tab, tabs, draft, list }: Props) {
    const ui = useAdminUi();
    const canEdit = !!draft?.canEdit;
    const restore = (v: LandingVersion) =>
        ui.confirm({
            icon: 'restore',
            title: `Restaurer la version ${v.number} ?`,
            body: 'Son contenu remplace le brouillon actuel. Le site ne change qu’après publication.',
            ok: 'Restaurer',
            onConfirm: () => router.post(`/landing-page/publications/${v.number}/restaurer`, {}, { preserveScroll: true }),
        });

    return (
        <>
            <Head title="Publications du site" />
            <LandingShell tab={tab} tabs={tabs} draft={draft}>
                <div style={{ marginBottom: 'var(--space-3)' }}>
                    <div style={{ fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-lg)' }}>Historique des publications</div>
                    <div style={{ fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>Chaque publication crée une version. Restaurer une version la recopie dans le brouillon, à publier ensuite.</div>
                </div>
                <TableFrame minWidth={820}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {['Version', 'Publiée le', 'Par', 'Note et sections modifiées'].map((c) => (
                                <th key={c} style={thStyle}>
                                    {c}
                                </th>
                            ))}
                            <th style={{ ...thStyle, textAlign: 'center' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {list.data.map((v) => (
                            <tr key={v.number} style={{ background: v.live ? 'var(--c-primary-50)' : 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <span style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                                        <TextCell strong>v{v.number}</TextCell>
                                        {v.live && <StatusBadge label="En ligne" tone="g" icon="public" />}
                                    </span>
                                    {v.restoredFrom && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>restaurée depuis v{v.restoredFrom}</span>}
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{v.publishedAt ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{v.publishedBy}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{v.note ?? '—'}</TextCell>
                                    {v.sections.length > 0 && <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{v.sections.join(' · ')}</span>}
                                </td>
                                <RowActions actions={canEdit && !v.live ? [{ icon: 'restore', label: `Restaurer la version ${v.number}`, onClick: () => restore(v) }] : []} />
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>
                <Pagination page={list.currentPage} lastPage={list.lastPage} from={list.from} to={list.to} total={list.total} onPage={(page) => router.get('/landing-page/publications', { page }, { preserveScroll: true })} />
            </LandingShell>
        </>
    );
}
