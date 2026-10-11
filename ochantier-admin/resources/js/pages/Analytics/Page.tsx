import BlockPage from '@/components/blocks/BlockPage';
import { useBlockActions } from '@/components/blocks/useBlockActions';
import type { BlockPageProps } from '@/types/blocks';

/**
 * Rapports & analyses : vue d’ensemble, rapport financier, répartition des missions, performance des chantiers,
 * indicateurs des ouvriers et bibliothèque. Les blocs et leurs actions sont décrits par le serveur.
 */
export default function AnalyticsPage(props: BlockPageProps) {
    const { run, dialog } = useBlockActions(props.sheets);

    return <BlockPage {...props} module="Rapports" run={run} dialog={dialog} />;
}
