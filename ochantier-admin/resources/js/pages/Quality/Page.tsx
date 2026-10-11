import BlockPage from '@/components/blocks/BlockPage';
import { useBlockActions } from '@/components/blocks/useBlockActions';
import type { BlockPageProps } from '@/types/blocks';

/**
 * Avis & litiges : page commune à toutes les vues du module (vue d’ensemble, avis, modération, signalements,
 * réclamations, litiges, médiations, recours, rapports, notifications, audit). Les blocs et leurs actions sont
 * décrits par le serveur.
 */
export default function QualityPage(props: BlockPageProps) {
    const { run, dialog } = useBlockActions(props.sheets);

    return <BlockPage {...props} module="Avis & litiges" run={run} dialog={dialog} />;
}
