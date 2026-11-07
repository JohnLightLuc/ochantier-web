import { stack } from '@/components/promoters/RelationTabs';
import { Block } from '@/components/workers/blocks';
import { Head } from '@inertiajs/react';

type Role = { code: string; label: string; description: string | null; permissions: { code: string; label: string }[] };

/** Rôles et droits, en consultation : ils portent les règles de double validation. */
export default function SettingsRoles({ roles }: { roles: Role[] }) {
    return (
        <>
            <Head title="Rôles et droits" />

            <div style={stack}>
                {roles.map((r) => (
                    <Block key={r.code} icon="shield_person" title={r.label} count={`${r.permissions.length} droits`}>
                        {r.description && <p style={{ margin: '0 0 var(--space-2)', color: 'var(--c-muted)' }}>{r.description}</p>}
                        <details>
                            <summary style={{ cursor: 'pointer', fontSize: 'var(--fs-sm)' }}>Voir les droits</summary>
                            <ul style={{ margin: 'var(--space-2) 0 0', paddingLeft: 'var(--space-5)', fontSize: 'var(--fs-sm)', columns: 2 }}>
                                {r.permissions.map((p) => (
                                    <li key={p.code}>{p.label}</li>
                                ))}
                            </ul>
                        </details>
                    </Block>
                ))}
            </div>
        </>
    );
}
