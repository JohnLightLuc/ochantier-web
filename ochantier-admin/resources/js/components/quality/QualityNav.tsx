import Button from '@/components/ui/Button';
import { router } from '@inertiajs/react';

const LINKS: [string, string, string][] = [
    ['/avis-litiges', 'Vue d’ensemble', 'dashboard'],
    ['/avis-litiges/avis', 'Avis', 'reviews'],
    ['/avis-litiges/reclamations', 'Réclamations', 'support_agent'],
    ['/avis-litiges/litiges', 'Litiges', 'gavel'],
];

/** Accès aux écrans du module Avis & litiges. */
export default function QualityNav({ current }: { current: string }) {
    return (
        <>
            {LINKS.filter(([href]) => href !== current).map(([href, label, icon]) => (
                <Button key={href} label={label} icon={icon} variant="secondary" onClick={() => router.visit(href)} />
            ))}
        </>
    );
}
