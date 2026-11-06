import Button from '@/components/ui/Button';
import { router } from '@inertiajs/react';

const LINKS: [string, string, string, boolean][] = [
    ['/paiements', 'Encaissements', 'payments', false],
    ['/paiements/echeances', 'Échéances', 'event_note', false],
    ['/paiements/a-reverser', 'À reverser', 'savings', true],
    ['/paiements/reversements', 'Reversements', 'account_balance_wallet', true],
    ['/paiements/lots', 'Lots', 'inventory_2', true],
    ['/paiements/numeros', 'Numéros à confirmer', 'phone_iphone', true],
];

/** Accès aux écrans du module Paiements ; ceux des reversements demandent leur propre droit. */
export default function PaymentNav({ current, payouts }: { current: string; payouts: boolean }) {
    return (
        <>
            {LINKS.filter(([href, , , restricted]) => href !== current && (payouts || !restricted)).map(([href, label, icon]) => (
                <Button key={href} label={label} icon={icon} variant="secondary" onClick={() => router.visit(href)} />
            ))}
        </>
    );
}
