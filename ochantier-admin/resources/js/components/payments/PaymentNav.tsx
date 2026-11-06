import Button from '@/components/ui/Button';
import { router } from '@inertiajs/react';

type Right = 'payouts' | 'commercials' | null;

const LINKS: [string, string, string, Right][] = [
    ['/paiements', 'Encaissements', 'payments', null],
    ['/paiements/echeances', 'Échéances', 'event_note', null],
    ['/paiements/regularisations', 'Régularisations', 'balance', null],
    ['/paiements/remboursements', 'Remboursements', 'undo', null],
    ['/paiements/a-reverser', 'À reverser', 'savings', 'payouts'],
    ['/paiements/reversements', 'Reversements', 'account_balance_wallet', 'payouts'],
    ['/paiements/lots', 'Lots', 'inventory_2', 'payouts'],
    ['/paiements/commerciaux', 'Commerciaux à payer', 'badge', 'commercials'],
    ['/paiements/commerciaux/versements', 'Versements aux commerciaux', 'receipt_long', 'commercials'],
    ['/paiements/numeros', 'Numéros à confirmer', 'phone_iphone', 'payouts'],
];

/** Accès aux écrans du module Paiements ; reversements et commerciaux demandent chacun leur droit. */
export default function PaymentNav({ current, can }: { current: string; can: { payouts: boolean; commercials: boolean } }) {
    return (
        <>
            {LINKS.filter(([href, , , right]) => href !== current && (!right || can[right])).map(([href, label, icon]) => (
                <Button key={href} label={label} icon={icon} variant="secondary" onClick={() => router.visit(href)} />
            ))}
        </>
    );
}
