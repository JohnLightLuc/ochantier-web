import { DataTable } from '@/components/workers/blocks';
import Pagination from '@/components/list/Pagination';
import { TextCell } from '@/components/list/Table';
import StatCard from '@/components/ui/StatCard';
import StatusBadge from '@/components/ui/StatusBadge';
import { fcfa } from '@/lib/format';
import type { CommissionLedgerData } from '@/types/commissions';
import { router } from '@inertiajs/react';

type Props = {
    ledger: CommissionLedgerData;
    /** Masque la colonne Professionnel (fiche d’un professionnel). */
    hideContractor?: boolean;
    title?: string;
    /** Changement de page (lignes paginées par 20). */
    onPage?: (page: number) => void;
};

/**
 * Registre des commissions O’CHANTIER PRO : totaux (collectée, à collecter, part nette due aux professionnels) et
 * une ligne par encaissement, avec le versement au professionnel quand il est préparé.
 */
export default function CommissionLedger({ ledger, hideContractor, title = 'Registre des commissions', onPage }: Props) {
    const from = ledger.total ? (ledger.currentPage - 1) * 20 + 1 : 0;
    const columns = ['Opération', ...(hideContractor ? [] : ['Professionnel']), 'Encaissé', 'Commission', 'Part du professionnel', 'Statut', 'Versement'];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <div data-component="StatGrid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 'var(--space-4)' }}>
                <StatCard label="Commission collectée" value={fcfa(ledger.totals.collected)} icon="savings" tone="g" hint={`sur ${fcfa(ledger.totals.collectedAmount)} encaissés`} />
                <StatCard label="Commission à collecter" value={fcfa(ledger.totals.toCollect)} icon="hourglass_top" tone="a" hint="Échéances et frais attendus" />
                <StatCard label="Part des professionnels" value={fcfa(ledger.totals.professionalNet)} icon="handyman" tone="b" hint="Encaissée pour leur compte, à leur reverser" />
            </div>
            <DataTable
                icon="percent"
                title={title}
                count={`${ledger.total} ligne${ledger.total > 1 ? 's' : ''}`}
                minWidth={hideContractor ? 900 : 1050}
                columns={columns}
                empty={{ icon: 'percent', title: 'Aucune commission', text: 'Les commissions apparaissent dès qu’un promoteur commande des devis ou paie l’acompte d’un forfait.' }}
                rows={ledger.lines.map((l) => ({
                    key: l.id,
                    onOpen: l.href ? () => router.visit(l.href!) : undefined,
                    cells: [
                        <TextCell strong>
                            {l.site}
                            <span style={{ display: 'block', fontWeight: 'var(--fw-regular)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>
                                {[l.reference, l.label, l.date].filter(Boolean).join(' · ')}
                            </span>
                        </TextCell>,
                        ...(hideContractor ? [] : [<TextCell>{l.contractor}</TextCell>]),
                        <TextCell>
                            {fcfa(l.base)}
                            <span style={{ display: 'block', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>payé par {l.payer}</span>
                        </TextCell>,
                        <TextCell strong>
                            {fcfa(l.commission)}
                            <span style={{ display: 'block', fontWeight: 'var(--fw-regular)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{l.rate}</span>
                        </TextCell>,
                        <TextCell muted={l.net === null}>{l.net === null ? '—' : fcfa(l.net)}</TextCell>,
                        <StatusBadge label={l.status.label} tone={l.status.tone} />,
                        l.payout ? (
                            <span style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1)' }}>
                                <StatusBadge label={l.payout.label} tone={l.payout.tone as never} />
                                <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{l.payout.reference}</span>
                            </span>
                        ) : (
                            <TextCell muted>{l.net === null ? '—' : l.serviceDone ? 'À reverser' : 'Après le service'}</TextCell>
                        ),
                    ],
                    actions: l.payout ? [{ icon: 'receipt_long', label: 'Voir le versement', onClick: () => router.visit(`/paiements/professionnels/versements/${l.payout!.id}`) }] : [],
                }))}
            />
            {onPage && ledger.lastPage > 1 && <Pagination page={ledger.currentPage} lastPage={ledger.lastPage} from={from} to={from + ledger.lines.length - 1} total={ledger.total} onPage={onPage} />}
        </div>
    );
}
