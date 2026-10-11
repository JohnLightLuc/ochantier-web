import { SectionNav } from '@/components/attendances/blocks';
import { FilterSelect } from '@/components/list/ListToolbar';
import { Checkbox, PersonCell, StackCell, TextCell } from '@/components/list/Table';
import Dialog from '@/components/overlays/Dialog';
import FormDialog from '@/components/overlays/FormDialog';
import StatusBadge from '@/components/ui/StatusBadge';
import { AlertList, DataTable, KeyValues, Toolbar } from '@/components/workers/blocks';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate } from '@/lib/format';
import type { AnyTone } from '@/types';
import type { AppProps, AppRow } from '@/types/attendances';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';

const GROUP_ACTIONS: [string, string, string][] = [
    ['arrivee', 'Pointer l’arrivée', 'login'],
    ['validation-arrivee', 'Valider les arrivées', 'how_to_reg'],
    ['depart', 'Pointer le départ', 'logout'],
    ['validation-depart', 'Valider les départs', 'task_alt'],
];

const ICONS: Record<string, string> = { arrivee: 'login', 'validation-arrivee': 'how_to_reg', depart: 'logout', 'validation-depart': 'task_alt', absence: 'person_off', 'validation-absence': 'verified' };

const RESULT_TONES: Record<string, AnyTone> = { Réussi: 'g', 'À vérifier': 'a', Ignoré: 'n', Échoué: 'r' };

/**
 * Simulation de l’Application Promoteur : le promoteur enregistre puis valide chaque arrivée et chaque départ
 * (heure serveur, localisation, photo, signature), individuellement ou en groupe.
 */
export default function AttendanceApp({ sections, sites, site, today, groups, labels, absenceMotifs }: AppProps) {
    const ui = useAdminUi();
    const { flash } = usePage();
    const [selected, setSelected] = useState<string[]>([]);
    const [dialog, setDialog] = useState<ReactNode>(null);
    const [result, setResult] = useState(flash.groupResult ?? null);
    const close = () => setDialog(null);
    const all = groups.flatMap((g) => g.rows);

    useEffect(() => setResult(flash.groupResult ?? null), [flash]);
    useEffect(() => setSelected([]), [site?.slug]);

    const url = (r: AppRow, action: string) => `/pointages/${r.reference}/application/${action}`;
    const toggle = (ref: string) => setSelected((s) => (s.includes(ref) ? s.filter((x) => x !== ref) : [...s, ref]));

    const run = (r: AppRow, action: string) => {
        const label = labels[action];
        if (action === 'arrivee' || action === 'depart') {
            const arrival = action === 'arrivee';
            return setDialog(
                <FormDialog
                    title={`${label} · ${r.worker.name}`}
                    body={`Affectation ${r.assignment} · ${r.site.name} · prévu ${arrival ? r.plannedStart : r.plannedEnd}. Éléments obligatoires : heure (serveur), localisation du promoteur, photo de l’ouvrier sur le chantier, note et signature électronique.`}
                    icon={ICONS[action]}
                    okLabel="Confirmer l’enregistrement"
                    url={url(r, action)}
                    onClose={close}
                    initial={{ time: arrival ? r.serverTimes.arrival : r.serverTimes.departure, zone: 'dans', photo: '1', signature: '1' }}
                    fields={[
                        { name: 'time', label: `${arrival ? 'Heure d’arrivée' : 'Heure de départ'} (heure serveur)`, type: 'select', required: true, options: [arrival ? r.serverTimes.arrival : r.serverTimes.departure] },
                        { name: 'zone', label: 'Localisation du promoteur', type: 'select', required: true, options: [{ value: 'dans', label: `Position GPS relevée · dans la zone du chantier (${site?.commune})` }, { value: 'hors', label: 'Position GPS relevée · hors zone' }] },
                        { name: 'photo', label: 'Photo de l’ouvrier sur le chantier', type: 'select', required: true, options: [{ value: '1', label: 'Photo prise et jointe' }, { value: '0', label: 'Aucune photo (anomalie)' }] },
                        { name: 'signature', label: 'Signature électronique', type: 'select', required: true, options: [{ value: '1', label: `Signé électroniquement par ${site?.promoter} · ${site?.manager ?? 'Responsable chantier'}` }, { value: '0', label: 'Non signé (anomalie)' }] },
                        { name: 'comment', label: 'Note (description)', type: 'textarea', required: true },
                    ]}
                />,
            );
        }
        if (action === 'absence') {
            return setDialog(
                <FormDialog
                    title={`Déclarer absent · ${r.worker.name}`}
                    icon="person_off"
                    tone="warning"
                    okLabel="Confirmer"
                    url={url(r, action)}
                    onClose={close}
                    fields={[
                        { name: 'motif', label: 'Motif', type: 'select', required: true, options: absenceMotifs },
                        { name: 'proof', label: 'Justificatif (si disponible)' },
                        { name: 'comment', label: 'Commentaire', type: 'textarea' },
                    ]}
                />,
            );
        }
        setDialog(
            <Dialog title={`${label} · ${r.worker.name}`} body="Application Promoteur · récapitulatif avant validation" icon="verified" tone="success" okLabel={label} onClose={close} onSubmit={() => router.post(url(r, action), {}, { preserveScroll: true, onSuccess: close })}>
                <KeyValues items={r.recap[action] ?? []} />
                <p style={{ margin: 'var(--space-3) 0 0', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>L’ouvrier ne peut pas valider son propre pointage. Après validation, toute modification passe par une demande de correction.</p>
            </Dialog>,
        );
    };

    const runGroup = (action: string) => {
        if (!selected.length) return ui.toast('Sélectionnez des ouvriers pour une opération groupée.', { tone: 'info' });
        router.post('/pointages/application/groupe', { refs: selected, action }, { preserveScroll: true, onSuccess: () => setSelected([]) });
    };

    return (
        <>
            <Head title="Application Promoteur · pointage" />
            <SectionNav sections={sections} />
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
                <FilterSelect label="Chantier" value={site?.slug ?? ''} options={sites} onChange={(v) => v && router.get('/pointages/application', { chantier: v }, { preserveScroll: true })} />
            </div>
            <AlertList
                alerts={[
                    ...(site ? [{ tone: 'info', icon: 'smartphone', title: 'Simulation de l’Application Promoteur', text: `Session authentifiée : ${site.promoter} · ${site.manager ?? 'responsable'} · ${formatDate(today)}. Chaque événement se fait en deux actions (enregistrer puis valider) ; l’heure provient du serveur. Le Back-office ne pointe pas à la place du promoteur.` }] : []),
                    ...(site?.blocked ? [{ tone: 'danger', icon: 'block', title: `Chantier ${site.status.toLowerCase()}`, text: 'Pointage impossible sur ce chantier.' }] : []),
                    ...(site && !site.blocked && !all.length ? [{ tone: 'warning', icon: 'event_busy', title: 'Aucun ouvrier attendu', text: 'Aucune affectation confirmée pour aujourd’hui sur ce chantier.' }] : []),
                ]}
            />
            <section style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-5)', minWidth: 0 }}>
                <Toolbar
                    actions={[
                        ...GROUP_ACTIONS.map(([a, l, icon]) => ({ label: `${l} (${selected.length})`, icon, variant: selected.length ? ('primary' as const) : ('secondary' as const), onClick: () => runGroup(a) })),
                        ...(selected.length ? [{ label: 'Vider la sélection', icon: 'close', onClick: () => setSelected([]) }] : []),
                    ]}
                />
                {groups.map((g) => (
                    <DataTable
                        key={g.title}
                        icon={g.icon}
                        title={g.title}
                        count={`${g.rows.length} ouvrier(s)`}
                        minWidth={1200}
                        columns={['', 'Ouvrier', 'Affectation', 'Horaires prévus', 'Arrivée', 'Départ', 'Journée', 'Indicateurs']}
                        empty={{ icon: 'check_circle', title: 'Aucun ouvrier', text: 'Aucun ouvrier dans cette étape.' }}
                        rows={g.rows.map((r) => ({
                            key: r.reference,
                            cells: [
                                site?.blocked ? <span key="c" /> : <Checkbox key="c" checked={selected.includes(r.reference)} onToggle={() => toggle(r.reference)} label={`Sélectionner ${r.worker.name}`} />,
                                <PersonCell key="w" name={r.worker.name} sub={`${r.worker.code} · ${r.worker.trade}`} />,
                                <TextCell key="a">{r.assignment}</TextCell>,
                                <TextCell key="h">{`${r.plannedStart} – ${r.plannedEnd}${r.night ? ' (nuit)' : ''}`}</TextCell>,
                                <StackCell key="ar" main={r.arrival.time ?? '—'} sub={r.arrival.status} />,
                                <StackCell key="de" main={r.departure.time ?? '—'} sub={r.departure.status} />,
                                <StatusBadge key="s" label={r.status.label} tone={r.status.tone} />,
                                <TextCell key="i" muted={!r.indicators.length}>
                                    {r.indicators.join(' · ') || '—'}
                                </TextCell>,
                            ],
                            actions: site?.blocked ? [] : g.actions.map((a) => ({ icon: ICONS[a], label: labels[a], onClick: () => run(r, a) })),
                            menu: [{ icon: 'visibility', label: 'Voir dans le Back-office', onClick: () => router.visit(`/pointages/${r.reference}`) }],
                        }))}
                    />
                ))}
            </section>

            {dialog}
            {result && (
                <Dialog title={result.summary} body={result.title} icon="groups" okLabel="Fermer" onClose={() => setResult(null)} onSubmit={() => setResult(null)}>
                    <p style={{ margin: '0 0 var(--space-3)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Un enregistrement distinct est créé pour chaque ouvrier ; une erreur n’annule pas les opérations réussies.</p>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
                        {result.items.map((it) => (
                            <div key={it.name} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2) var(--space-3)', border: '1px solid var(--c-line-soft)', borderRadius: 'var(--radius-lg)' }}>
                                <div style={{ flex: 1 }}>
                                    <div style={{ fontWeight: 'var(--fw-semibold)' }}>{it.name}</div>
                                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{it.detail}</div>
                                </div>
                                <StatusBadge label={it.result} tone={RESULT_TONES[it.result] ?? 'n'} />
                            </div>
                        ))}
                    </div>
                </Dialog>
            )}
        </>
    );
}
