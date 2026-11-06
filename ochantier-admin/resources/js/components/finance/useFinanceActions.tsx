import Dialog from '@/components/overlays/Dialog';
import { useBlockActions } from '@/components/blocks/useBlockActions';
import Field, { SelectInput } from '@/components/ui/Field';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { FinancePageProps } from '@/types/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { fcfa } from '@/lib/format';

const GROUPS = ['Aucun', 'Par mois', 'Par chantier', 'Par promoteur', 'Par métier', 'Par commune'];

type ExportProps = Pick<FinancePageProps, 'reports' | 'bases' | 'basis' | 'query' | 'exportInfo'> & { report: string; onClose: () => void };

/** Construction d’un rapport : rapport, base temporelle, regroupement, format, niveau de détail, graphiques et définitions. */
function ExportDialog({ reports, bases, basis, query, exportInfo, report, onClose }: ExportProps) {
    const ui = useAdminUi();
    const [values, setValues] = useState<Record<string, string>>({
        rapport: reports.some((r) => r.value === report) ? report : (reports[0]?.value ?? ''),
        base: basis,
        regroupement: 'Aucun',
        colonnes: 'Colonnes standard',
        format: 'Excel',
        niveau: 'Détaillé',
        graphiques: 'Non',
        definitions: 'Oui',
    });
    const fields: [string, string, (string | { value: string; label: string })[], boolean?][] = [
        ['rapport', 'Rapport', reports, true],
        ['base', 'Base temporelle', Object.entries(bases).map(([value, label]) => ({ value, label }))],
        ['regroupement', 'Regroupement', GROUPS],
        ['colonnes', 'Colonnes', ['Colonnes standard', 'Toutes les colonnes']],
        ['format', 'Format', ['PDF', 'Excel', 'CSV']],
        ['niveau', 'Niveau de détail', ['Synthèse', 'Détaillé']],
        ['graphiques', 'Inclure les graphiques', ['Oui', 'Non']],
        ['definitions', 'Inclure les définitions', ['Oui', 'Non']],
    ];
    const submit = () => {
        const { colonnes: _columns, ...params } = values;
        window.location.href = `/finance-reporting/rapports/generer?${new URLSearchParams({ ...query, ...params }).toString()}`;
        const name = reports.find((r) => r.value === values.rapport)?.label ?? 'Rapport';
        ui.toast(`${name} généré (${values.format})${values.format === 'PDF' ? ' · version imprimable à enregistrer en PDF' : ''}.`);
        onClose();
    };

    return (
        <Dialog title="Exporter" body={exportInfo} icon="download" wide okLabel="Générer" okIcon="download" onClose={onClose} onSubmit={submit}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,minmax(0,1fr))', gap: 'var(--space-3)' }}>
                {fields.map(([name, label, options, full]) => (
                    <Field key={name} label={label} required={['rapport', 'base', 'format'].includes(name)} span={full ? 'full' : undefined}>
                        <SelectInput value={values[name]} onChange={(e) => setValues({ ...values, [name]: e.target.value })} options={options.map((o) => (typeof o === 'string' ? { value: o, label: o } : o))} />
                    </Field>
                ))}
            </div>
        </Dialog>
    );
}

/**
 * Actions de Finance & Reporting : actions communes des pages décrites par le serveur, plus les règles de
 * commission (panneau en lecture seule) et la construction d’un rapport. Rendre `dialog` dans la page.
 */
export function useFinanceActions(props: FinancePageProps) {
    const ui = useAdminUi();

    const rates = () => {
        const r = props.rule;
        ui.openPanel({
            kicker: 'Règles de commission · lecture seule',
            title: 'Taux de commission',
            badges: [{ label: r.ok ? 'Règle active' : 'Aucune règle active', tone: r.ok ? 'g' : 'r' }],
            meta: [
                ['Règle active', `${r.name} · ${r.version}`],
                ['Commission promoteur (Y)', `${r.y} %${r.y ? '' : ' · désactivée'}`],
                ['Commission ouvrier (Z)', `${r.z} %${r.z ? '' : ' · désactivée'}`],
                ['Règle partagée', 'Y ajouté au montant payé par le promoteur, Z prélevé sur la rémunération ouvrier ; acomptes : Y + Z à la charge du promoteur'],
                ['Type', r.type],
                ['Base de calcul', r.base],
                ['Minimum', r.min ? fcfa(r.min) : 'Aucun'],
                ['Maximum', r.max ? fcfa(r.max) : 'Aucun'],
                ['Date d’effet', `${formatDate(r.from)}${r.to ? ` → ${formatDate(r.to)}` : ' · sans date de fin'}`],
                ['Périmètre', `${r.scope} · ${r.trade} · ${r.target}`],
                ['Statut', r.status],
            ],
            listTitle: 'Versions et historique',
            list: [
                { icon: 'verified', name: `${r.version} · ${r.name}`, sub: `En vigueur depuis le ${formatDate(r.from)}`, badge: 'Active', badgeTone: 'g' },
                ...r.previous.map((o) => ({ icon: 'inventory_2', tone: 'n' as const, name: `${o.version} · ${o.name}`, sub: `${formatDate(o.from)} → ${formatDate(o.to)}`, badge: o.status, badgeTone: 'n' as const })),
            ],
            text: 'Finance & Reporting lit ces valeurs dans Paramètres et reste en lecture analytique. Chaque commission conserve la version de règle en vigueur à la date de l’opération.',
            actions: [
                { label: 'Fermer', icon: 'close', onClick: () => {} },
                { label: 'Modifier dans Paramètres', icon: 'settings', variant: 'primary', onClick: () => (props.canParams ? router.visit('/parametres') : ui.toast('Action non autorisée pour votre rôle · accès aux Paramètres.', { tone: 'error' })) },
            ],
        });
    };

    return useBlockActions(props.sheets, (action, show, close) => {
        if (action.type === 'rates') {
            rates();
            return true;
        }
        if (action.type === 'export') {
            show(<ExportDialog reports={props.reports} bases={props.bases} basis={props.basis} query={props.query} exportInfo={props.exportInfo} report={action.report as string} onClose={close} />);
            return true;
        }
        return false;
    });
}
