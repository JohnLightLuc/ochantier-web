import { FilterSelect } from '@/components/list/ListToolbar';
import { PersonCell, ProgressCell, StackCell, TextCell } from '@/components/list/Table';
import StatusBadge from '@/components/ui/StatusBadge';
import { DataTable, type ToolbarAction } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { Profile, ResultRow } from '@/types/matching';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { decimal, fcfa, SENT, type MatchingActions } from './useMatchingActions';

type Row = Profile & { offer?: ResultRow['offer'] };

const COMPARE_KEY = (offerId: number) => `och_mx_cmp_${offerId}`;

/** Profils mis de côté pour la comparaison d’une offre (quatre au plus). */
export function readCompare(offerId: number): string[] {
    try {
        return JSON.parse(sessionStorage.getItem(COMPARE_KEY(offerId)) ?? '[]');
    } catch {
        return [];
    }
}

export function writeCompare(offerId: number, ids: string[]): void {
    try {
        sessionStorage.setItem(COMPARE_KEY(offerId), JSON.stringify(ids));
    } catch {
        // Stockage indisponible : la comparaison retombe sur les trois meilleurs profils.
    }
}

const sorters: Record<string, (a: Row, b: Row) => number> = {
    pertinence: (a, b) => b.score - a.score || Number(!!a.conflict) - Number(!!b.conflict),
    score: (a, b) => b.score - a.score,
    exp: (a, b) => b.experience - a.experience,
    prox: (a, b) => a.km - b.km,
    cout: (a, b) => a.cost - b.cost,
    dispo: (a, b) => a.availableOn.localeCompare(b.availableOn),
    note: (a, b) => b.rating - a.rating,
    fiab: (a, b) => b.acceptance - a.acceptance,
    activite: (a, b) => b.lastActivity.localeCompare(a.lastActivity),
};

type Props = {
    rows: Row[];
    /** Liste de plusieurs besoins : colonne et filtres « Besoin » et « Métier ». */
    withNeed?: boolean;
    /** Coût de l’offre (liste d’un seul besoin) pour le filtre « Coût ». */
    offerCost?: number;
    levels: string[];
    sorts: Record<string, string>;
    actions: MatchingActions;
    tools?: ToolbarAction[];
    title: string;
    count: (shown: number, total: number) => string;
};

/** Tableau de recommandations filtrable (score, niveau, distance, coût, disponibilité…) et triable. */
export default function RecommendationTable({ rows, withNeed, offerCost, levels, sorts, actions, tools = [], title, count }: Props) {
    const ui = useAdminUi();
    const [f, setF] = useState<Record<string, string>>({});
    const set = (k: string) => (v: string) => setF((x) => ({ ...x, [k]: v }));
    const uniq = (values: string[]) => [...new Set(values)].sort((a, b) => a.localeCompare(b, 'fr')).map((v) => ({ value: v, label: v }));
    const opts = (values: string[]) => values.map((v) => ({ value: v, label: v }));

    const visible = useMemo(() => {
        const list = rows.filter((r) => {
            const st = r.status.label;
            const cost = r.offer?.cost ?? offerCost ?? 0;
            return (
                (!f.lv || r.level.label === f.lv) &&
                (!f.min || r.score >= Number(f.min)) &&
                (!f.com || r.commune === f.com) &&
                (!f.dist || (f.dist === '> 20 km' ? r.km > 20 : r.km <= parseInt(f.dist.slice(2), 10))) &&
                (!f.exp || r.experience >= parseInt(f.exp.slice(2), 10)) &&
                (!f.cout || (f.cout === 'Dans le budget' ? r.cost <= cost : r.cost > cost)) &&
                (!f.dispo || r.availability === f.dispo) &&
                (!f.ver || r.verified === (f.ver === 'Oui')) &&
                (!f.cert || !!r.certs.length === (f.cert === 'Oui')) &&
                (!f.note || r.rating >= parseFloat(f.note)) &&
                (!f.dos || r.dossier >= parseInt(f.dos.slice(2), 10)) &&
                (!f.conf || !r.conflict === (f.conf === 'Sans conflit')) &&
                (!f.sel || /Sélectionné|validation/.test(st) === (f.sel === 'Oui')) &&
                (!f.nv || r.newcomer) &&
                (!f.anc || r.former) &&
                (!f.prev || r.previous) &&
                (!f.st || st === f.st) &&
                (!f.met || r.trade === f.met) &&
                (!f.bs || String(r.offerId) === f.bs)
            );
        });
        return [...list].sort(sorters[f.tri ?? 'pertinence'] ?? sorters.pertinence);
    }, [rows, f, offerCost]);

    const addCompare = (r: Row) => {
        const ids = readCompare(r.offerId);
        if (ids.includes(r.matchId)) return router.visit(`/matching/besoins/${r.offerId}/comparaison?ids=${ids.join(',')}`);
        if (ids.length >= 4) return ui.toast('Comparaison limitée à 4 profils.', { tone: 'error' });
        writeCompare(r.offerId, [...ids, r.matchId]);
        ui.toast(`${r.name} ajouté à la comparaison (${ids.length + 1}/4).`);
    };

    const filters: [string, string, { value: string; label: string }[]][] = [
        ...(withNeed
            ? ([
                  ['bs', 'Besoin', [...new Map(rows.map((r) => [String(r.offerId), { value: String(r.offerId), label: `${r.offer?.reference} · ${r.offer?.site}` }])).values()]],
                  ['met', 'Métier', uniq(rows.map((r) => r.trade))],
              ] as [string, string, { value: string; label: string }[]][])
            : []),
        ['min', 'Score minimum', ['50', '60', '70', '80', '90'].map((v) => ({ value: v, label: `≥ ${v}` }))],
        ['lv', 'Niveau', opts(levels)],
        ['st', 'Statut', opts(['Recommandé', 'Sélectionné', 'En attente de validation', 'Transmis aux Affectations', 'Confirmé par l’ouvrier', 'Rejeté', 'Remplaçant principal', 'Réserve'])],
        ['com', 'Commune', uniq(rows.map((r) => r.commune))],
        ['dist', 'Distance', opts(['≤ 5 km', '≤ 10 km', '≤ 20 km', '> 20 km'])],
        ['exp', 'Expérience', opts(['≥ 1 an', '≥ 3 ans', '≥ 5 ans', '≥ 8 ans'])],
        ['cout', 'Coût', opts(['Dans le budget', 'Au-dessus du budget'])],
        ['dispo', 'Disponibilité', opts(['Disponible', 'Préaffecté', 'En mission'])],
        ['ver', 'Profil vérifié', opts(['Oui', 'Non'])],
        ['cert', 'Certification', opts(['Oui', 'Non'])],
        ['note', 'Note minimale', ['3.5', '4', '4.5'].map((v) => ({ value: v, label: `≥ ${decimal(Number(v))} / 5` }))],
        ['dos', 'Qualité du dossier', opts(['≥ 70 %', '≥ 85 %'])],
        ['conf', 'Conflit', opts(['Sans conflit', 'Avec conflit'])],
        ['sel', 'Déjà sélectionné', opts(['Oui', 'Non'])],
        ['nv', 'Nouveau profil', opts(['Oui'])],
        ['anc', 'Ancien collaborateur', opts(['Oui'])],
        ['prev', 'Recommandation précédente', opts(['Oui'])],
    ];

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                {filters.map(([k, label, options]) => (
                    <FilterSelect key={k} label={label} value={f[k] ?? ''} options={options} onChange={set(k)} />
                ))}
                <FilterSelect label="Tri" value={f.tri ?? ''} options={Object.entries(sorts).map(([value, label]) => ({ value, label: `Trier : ${label.toLowerCase()}` }))} onChange={set('tri')} />
            </div>
            <DataTable
                icon="group"
                title={title}
                count={count(visible.length, rows.length)}
                minWidth={withNeed ? 1500 : 1400}
                tools={[{ label: 'Réinitialiser les filtres', icon: 'filter_alt_off', onClick: () => setF({}) }, ...tools]}
                columns={['Match', 'Ouvrier', ...(withNeed ? ['Besoin'] : []), 'Score', 'Niveau', 'Distance', 'Coût', 'Disponibilité', 'Alertes', 'Statut']}
                empty={{ icon: 'search_off', title: 'Aucun résultat', text: 'Aucune recommandation ne correspond aux filtres.' }}
                rows={visible.map((r) => ({
                    key: r.matchId,
                    onOpen: () => router.visit(`/matching/${r.matchId}`),
                    cells: [
                        <TextCell strong>{r.matchId.toUpperCase()}</TextCell>,
                        <PersonCell name={r.name} sub={`${r.code} · ${r.specialty ?? ''}`} />,
                        ...(withNeed && r.offer ? [<StackCell main={r.offer.site} sub={`${r.offer.reference} · ${r.offer.trade}`} />] : []),
                        <ProgressCell value={r.score} />,
                        <StatusBadge label={r.level.label} tone={r.level.tone} />,
                        <TextCell>
                            {decimal(r.km)} km · {r.minutes} min
                        </TextCell>,
                        <TextCell>{fcfa(r.cost)}/j</TextCell>,
                        <TextCell>
                            {r.availability} · {formatDate(r.availableOn)}
                        </TextCell>,
                        <TextCell muted={!r.warnings.length}>{r.warnings.length ? `${r.warnings.length} avert.` : '—'}</TextCell>,
                        <StatusBadge label={r.status.label} tone={r.status.tone} />,
                    ],
                    actions: [{ icon: 'visibility', label: 'Voir le profil', onClick: () => actions.drawer(r) }, ...(r.blocked || SENT.test(r.status.label) ? [] : [{ icon: r.selected ? 'remove' : 'add', label: r.selected ? 'Désélectionner' : 'Sélectionner', onClick: () => actions.toggle(r) }])],
                    menu: [
                        { icon: 'compare', label: 'Ajouter à la comparaison', onClick: () => addCompare(r) },
                        { icon: 'swap_horiz', label: 'Remplaçant principal', onClick: () => actions.replacement(r, 'Remplaçant principal') },
                        { icon: 'bookmark', label: 'Réserve', onClick: () => actions.replacement(r, 'Réserve') },
                        { icon: 'block', label: 'Rejeter', danger: true, onClick: () => actions.reject(r) },
                    ],
                }))}
            />
        </div>
    );
}
