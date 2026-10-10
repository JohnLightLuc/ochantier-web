import StateBlock from '@/components/ui/StateBlock';
import { Head, router } from '@inertiajs/react';

type Props = {
    module: { key: string; label: string; icon: string; title: string; subtitle: string };
};

/** Module pas encore intégré depuis la maquette. */
export default function ModulePlaceholder({ module }: Props) {
    return (
        <>
            <Head title={module.label} />
            <StateBlock
                kind="nodata"
                icon={module.icon}
                title={`Module « ${module.label} » en cours d’intégration`}
                text="Cet écran existe dans la maquette et sera intégré dans une prochaine étape."
                actionLabel="Retour à la vue d’ensemble"
                onAction={() => router.visit('/')}
                minHeight="320px"
            />
        </>
    );
}
