import BlockPage from '@/components/blocks/BlockPage';
import { useFinanceActions } from '@/components/finance/useFinanceActions';
import type { FinancePageProps } from '@/types/finance';

/**
 * Finance & Reporting : page commune à toutes les vues du module (vue d’ensemble, journal, analyses, créances,
 * reversements, trésorerie, remboursements, frais, rapprochements, risques, objectifs, prévisions, clôtures,
 * rapports, définitions, audit). Les blocs et leurs actions sont décrits par le serveur.
 */
export default function FinancePage(props: FinancePageProps) {
    const { run, dialog } = useFinanceActions(props);

    return <BlockPage {...props} module="Finance & Reporting" run={run} dialog={dialog} periodLabel={`${props.period.range} · ${props.bases[props.basis].toLowerCase()}`} />;
}
