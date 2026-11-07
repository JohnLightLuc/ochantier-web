import { FilterSelect, SearchInput } from '@/components/list/ListToolbar';
import Pagination from '@/components/list/Pagination';
import { StackCell, TableFrame, TextCell, tdStyle, thStyle } from '@/components/list/Table';
import FormDialog from '@/components/overlays/FormDialog';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StateBlock from '@/components/ui/StateBlock';
import StatusBadge from '@/components/ui/StatusBadge';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export type AdminRow = {
    id: string;
    name: string;
    email: string;
    department: string | null;
    status: { value: string; label: string; tone: string };
    roles: { code: string; label: string }[];
    lastLoginAt: string | null;
};

type Filters = { q?: string; statut?: string; role?: string; page?: string };

type Props = {
    admins: { data: AdminRow[]; currentPage: number; lastPage: number; total: number; from: number; to: number };
    filters: Filters;
    roles: Option[];
    can: { create: boolean; update: boolean; delete: boolean };
};

const COLUMNS = ['Nom', 'Rôles', 'Service', 'Dernière connexion', 'Statut'];
const STATUSES = [
    { value: 'invite', label: 'Invité' },
    { value: 'actif', label: 'Actif' },
    { value: 'suspendu', label: 'Suspendu' },
    { value: 'desactive', label: 'Désactivé' },
];

/** Comptes du Back Office. */
export default function SettingsAdmins({ admins, filters, roles, can }: Props) {
    const [inviting, setInviting] = useState(false);
    const apply = (changes: Filters) => {
        const next: Filters = { ...filters, page: undefined, ...changes };
        router.get('/parametres/utilisateurs', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="Utilisateurs & rôles" />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                <Button label="Paramètres" icon="settings" variant="secondary" onClick={() => router.visit('/parametres')} />
                <Button label="Rôles et droits" icon="shield_person" variant="secondary" onClick={() => router.visit('/parametres/utilisateurs/roles')} />
                {can.create && <Button label="Inviter un utilisateur" icon="person_add" onClick={() => setInviting(true)} />}
            </div>

            <section data-component="ListPanel" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5) var(--space-6)' }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 'var(--space-2_5)', marginBottom: 'var(--space-3)' }}>
                    <SearchInput value={filters.q ?? ''} placeholder="Rechercher par nom ou e-mail" onChange={(q) => apply({ q })} />
                    <FilterSelect label="Statut" value={filters.statut ?? ''} options={STATUSES} onChange={(statut) => apply({ statut })} />
                    <FilterSelect label="Rôle" value={filters.role ?? ''} options={roles} onChange={(role) => apply({ role })} />
                </div>

                <TableFrame minWidth={820}>
                    <thead>
                        <tr style={{ background: 'var(--c-surface-3)' }}>
                            {COLUMNS.map((label) => (
                                <th key={label} style={thStyle}>
                                    {label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {admins.data.map((a) => (
                            <tr key={a.id} onClick={() => router.visit(`/parametres/utilisateurs/${a.id}`)} className="och-hover-soft" style={{ cursor: 'pointer', background: 'var(--c-surface)' }}>
                                <td style={tdStyle}>
                                    <StackCell main={a.name} sub={a.email} strong />
                                </td>
                                <td style={tdStyle}>
                                    <TextCell>{a.roles.map((r) => r.label).join(', ') || '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{a.department ?? '—'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <TextCell muted>{a.lastLoginAt ?? 'Jamais'}</TextCell>
                                </td>
                                <td style={tdStyle}>
                                    <StatusBadge label={a.status.label} tone={a.status.tone as never} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </TableFrame>

                {admins.data.length === 0 && (
                    <div style={{ paddingTop: 'var(--space-4)' }}>
                        <StateBlock kind="nodata" icon="group" title="Aucun utilisateur" text="Modifiez la recherche ou les filtres." />
                    </div>
                )}

                <Pagination page={admins.currentPage} lastPage={admins.lastPage} from={admins.from} to={admins.to} total={admins.total} onPage={(page) => apply({ page: String(page) })} />
            </section>

            {inviting && (
                <FormDialog
                    title="Inviter un utilisateur"
                    body="Un lien valable 72 heures part par e-mail. La personne y choisit son mot de passe, et son compte devient actif."
                    icon="person_add"
                    okLabel="Envoyer l’invitation"
                    url="/parametres/utilisateurs"
                    onClose={() => setInviting(false)}
                    fields={[
                        { name: 'first_name', label: 'Prénom', required: true },
                        { name: 'last_name', label: 'Nom', required: true },
                        { name: 'email', label: 'E-mail', required: true, full: true },
                        { name: 'role', label: 'Rôle', type: 'select', required: true, options: roles },
                        { name: 'department', label: 'Service' },
                    ]}
                />
            )}
        </>
    );
}
