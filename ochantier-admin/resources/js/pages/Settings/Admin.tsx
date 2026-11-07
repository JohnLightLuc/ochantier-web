import FormDialog from '@/components/overlays/FormDialog';
import Button from '@/components/ui/Button';
import type { Option } from '@/components/ui/Field';
import StatusBadge from '@/components/ui/StatusBadge';
import { Block, KeyValues } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { AdminRow } from './Admins';

type Detail = AdminRow & {
    phone: string | null;
    suspendedReason: string | null;
    invitedBy: string | null;
    createdAt: string | null;
    sessions: number;
    invite: { pending: boolean; expiresAt: string | null };
    actions: { resend: boolean; revoke: boolean; roles: boolean; suspend: boolean; reactivate: boolean; deactivate: boolean };
};

type Can = { create: boolean; update: boolean; delete: boolean };

/** Fiche d’un utilisateur du Back Office : accès, rôles et invitation. */
export default function SettingsAdmin({ admin: a, roles, can }: { admin: Detail; roles: Option[]; can: Can }) {
    const ui = useAdminUi();
    const [dialog, setDialog] = useState<'suspend' | 'deactivate' | null>(null);
    const [selected, setSelected] = useState(a.roles.map((r) => r.code));
    const base = `/parametres/utilisateurs/${a.id}`;
    const post = (path: string) => router.post(`${base}/${path}`, {}, { preserveScroll: true });
    const x = a.actions;
    const changed = [...selected].sort().join() !== a.roles.map((r) => r.code).sort().join();
    const toggle = (code: string) => setSelected((list) => (list.includes(code) ? list.filter((c) => c !== code) : [...list, code]));

    return (
        <>
            <Head title={a.name} />

            <div style={{ display: 'flex', justifyContent: 'flex-end', flexWrap: 'wrap', gap: 'var(--space-2)', marginTop: -6 }}>
                {can.delete && x.deactivate && <Button label="Désactiver" icon="person_off" variant="danger-soft" onClick={() => setDialog('deactivate')} />}
                {can.update && x.suspend && <Button label="Suspendre" icon="pause_circle" variant="secondary" onClick={() => setDialog('suspend')} />}
                {can.update && x.revoke && <Button label="Révoquer l’invitation" icon="link_off" variant="secondary" onClick={() => router.delete(`${base}/invitation`, { preserveScroll: true })} />}
                {can.update && x.resend && <Button label="Renvoyer l’invitation" icon="send" onClick={() => post('invitation')} />}
                {can.update && x.reactivate && <Button label="Réactiver" icon="play_circle" onClick={() => post('reactivation')} />}
            </div>

            <Block icon="badge" title={a.name}>
                <KeyValues
                    items={[
                        ['Statut', <StatusBadge label={a.status.label} tone={a.status.tone as never} />],
                        ['E-mail', a.email],
                        ['Téléphone', a.phone],
                        ['Service', a.department],
                        ['Invité par', a.invitedBy],
                        ['Compte créé le', a.createdAt],
                        ['Dernière connexion', a.lastLoginAt ?? 'Jamais'],
                        ['Sessions ouvertes', String(a.sessions)],
                        ['Invitation', a.status.value === 'invite' ? (a.invite.pending ? `Valable jusqu’au ${a.invite.expiresAt}` : 'Expirée ou révoquée') : null],
                        ['Motif de suspension', a.suspendedReason],
                    ]}
                />
            </Block>

            <Block icon="shield_person" title="Rôles">
                <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                    {roles.map((r) => (
                        <label key={r.value} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                            <input type="checkbox" checked={selected.includes(r.value)} disabled={!(can.update && x.roles)} onChange={() => toggle(r.value)} />
                            {r.label}
                        </label>
                    ))}
                </div>
                {can.update && x.roles && changed && (
                    <div style={{ marginTop: 'var(--space-3)' }}>
                        <Button label="Enregistrer les rôles" icon="save" disabled={selected.length === 0} onClick={() => ui.confirm({ tone: 'warning', icon: 'shield_person', title: 'Modifier les rôles ?', body: 'Les sessions de cette personne sont fermées : elle devra se reconnecter.', ok: 'Enregistrer', onConfirm: () => router.put(`${base}/roles`, { roles: selected }, { preserveScroll: true }) })} />
                    </div>
                )}
            </Block>

            {dialog === 'suspend' && <FormDialog title="Suspendre ce compte ?" body="Ses sessions sont fermées tout de suite. Le compte peut être réactivé." icon="pause_circle" tone="warning" okLabel="Suspendre" url={`${base}/suspension`} onClose={() => setDialog(null)} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />}
            {dialog === 'deactivate' && <FormDialog title="Désactiver ce compte ?" body="C’est définitif. Le compte ne peut plus se connecter, et ses actions restent au journal." icon="person_off" tone="danger" okLabel="Désactiver" url={`${base}/desactivation`} onClose={() => setDialog(null)} fields={[{ name: 'reason', label: 'Motif', type: 'textarea', required: true }]} />}
        </>
    );
}
