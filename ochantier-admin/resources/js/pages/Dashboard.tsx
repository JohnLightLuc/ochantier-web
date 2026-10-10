import { Head } from '@inertiajs/react';

type Props = {
    appName: string;
    laravelVersion: string;
};

export default function Dashboard({ appName, laravelVersion }: Props) {
    return (
        <>
            <Head title="Tableau de bord" />
            <main
                style={{
                    minHeight: '100vh',
                    display: 'grid',
                    placeItems: 'center',
                    padding: 'var(--space-6)',
                }}
            >
                <section
                    style={{
                        width: '100%',
                        maxWidth: 'var(--auth-card-w)',
                        background: 'var(--c-surface)',
                        border: '1px solid var(--c-line)',
                        borderRadius: 'var(--radius-2xl)',
                        boxShadow: 'var(--shadow-lg)',
                        padding: 'var(--space-8)',
                        animation: 'och-rise var(--dur) var(--ease)',
                    }}
                >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
                        <span
                            className="material-symbols-rounded"
                            style={{ fontSize: 'var(--icon-lg)', color: 'var(--c-primary)' }}
                        >
                            construction
                        </span>
                        <h1
                            style={{
                                margin: 0,
                                fontSize: 'var(--fs-3xl)',
                                fontWeight: 'var(--fw-bold)',
                                color: 'var(--c-primary)',
                            }}
                        >
                            {appName}
                        </h1>
                    </div>
                    <p style={{ color: 'var(--c-muted)', lineHeight: 'var(--lh-normal)' }}>
                        Socle Laravel {laravelVersion} + Inertia + React prêt. Les écrans de la maquette
                        seront migrés module par module.
                    </p>
                </section>
            </main>
        </>
    );
}
