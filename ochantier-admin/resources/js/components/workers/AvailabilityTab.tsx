import FormDialog from '@/components/overlays/FormDialog';
import { LocationCell, TextCell } from '@/components/list/Table';
import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import StatusBadge from '@/components/ui/StatusBadge';
import { useAdminUi } from '@/contexts/AdminUiContext';
import { formatDate, todayIso } from '@/lib/format';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { AlertList, DataTable, Toolbar } from './blocks';
import { useAlerts, type TabProps } from './tabs';

/** Couleurs du calendrier : fond, texte, abréviation. */
const DAY_TYPES: Record<string, [string, string, string]> = {
    Disponible: ['var(--c-success-bg)', 'var(--c-success-fg)', 'Dispo.'],
    'Partiellement disponible': ['var(--c-warning-bg)', 'var(--c-warning-fg)', 'Partiel'],
    Préaffecté: ['var(--c-info-bg)', 'var(--c-info-fg)', 'Préaffecté'],
    Affecté: ['var(--c-purple-bg)', 'var(--c-purple-fg)', 'Affecté'],
    'En mission': ['var(--c-orange-bg)', 'var(--c-orange-fg)', 'Mission'],
    Indisponible: ['var(--c-danger-bg)', 'var(--c-danger-fg)', 'Indispo.'],
    Repos: ['var(--c-neutral-bg)', 'var(--c-neutral-fg)', 'Repos'],
    'Absence déclarée': ['var(--c-surface)', 'var(--c-danger-fg)', 'Absence'],
};

const PERIOD_TYPES = [
    { value: 'available', label: 'Disponible' },
    { value: 'partial', label: 'Partiellement disponible' },
    { value: 'unavailable', label: 'Indisponible' },
    { value: 'rest', label: 'Repos' },
    { value: 'declared_absence', label: 'Absence déclarée' },
];

const INACTIVE_ASSIGNMENTS = ['Terminée', 'Annulée', 'Refusée', 'Remplacée'];

const pad = (n: number) => String(n).padStart(2, '0');

/** Calendrier mensuel des disponibilités, conflits et périodes déclarées. */
export default function AvailabilityTab(props: TabProps) {
    const { worker: w, periods, demo, options, actions } = props;
    const ui = useAdminUi();
    const alerts = useAlerts(props);
    const [month, setMonth] = useState('2026-09');
    const archived = w.status.value === 'archived';
    const blocked = archived || w.status.value === 'suspended';
    const base = `/ouvriers/${w.slug}`;
    const today = todayIso();

    const [year, mon] = month.split('-').map(Number);
    const first = new Date(year, mon - 1, 1);
    const daysInMonth = new Date(year, mon, 0).getDate();
    const offset = (first.getDay() + 6) % 7;
    const move = (n: number) => {
        const d = new Date(year, mon - 1 + n, 1);
        setMonth(`${d.getFullYear()}-${pad(d.getMonth() + 1)}`);
    };

    /** Type d’un jour : période déclarée en priorité, puis affectation, puis dimanche / disponible. */
    const dayInfo = (iso: string) => {
        const period = [...periods].reverse().find((p) => iso >= p.startsOn && iso <= p.endsOn);
        const assignment = demo.assignments.find((a) => iso >= a.start && iso <= a.end && !INACTIVE_ASSIGNMENTS.includes(a.status));
        const assignmentType = assignment ? (assignment.status === 'En cours' ? 'En mission' : ['Confirmée', 'Acceptée', 'Remplacement demandé'].includes(assignment.status) ? 'Affecté' : 'Préaffecté') : null;
        const conflict = !!(period && assignmentType && period.type.blocks);
        const type = period ? period.type.label : (assignmentType ?? (new Date(`${iso}T12:00:00`).getDay() === 0 ? 'Repos' : blocked ? 'Indisponible' : 'Disponible'));
        return { type, conflict, period, assignment };
    };

    const declare = (from?: string, to?: string) =>
        actions.open(
            <FormDialog
                title="Déclarer une disponibilité"
                body={w.name}
                icon="event_available"
                okLabel="Enregistrer"
                url={`${base}/periodes`}
                onClose={actions.close}
                initial={{ starts_on: from ?? today, ends_on: to ?? from ?? today, zone: w.commune ?? '' }}
                fields={[
                    { name: 'starts_on', label: 'Du', type: 'date', required: true },
                    { name: 'ends_on', label: 'Au', type: 'date', required: true },
                    { name: 'type', label: 'Type de disponibilité', type: 'select', required: true, full: true, options: PERIOD_TYPES },
                    { name: 'reason', label: 'Motif', full: true },
                    { name: 'zone', label: 'Zone géographique', type: 'select', options: options.communes },
                    { name: 'comment', label: 'Commentaire interne', type: 'textarea' },
                ]}
            />,
        );

    const title = first.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }).replace(/^./, (c) => c.toUpperCase());

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            <AlertList alerts={alerts} />
            {!archived && (
                <Toolbar
                    actions={[
                        { label: 'Déclarer une indisponibilité', icon: 'event_busy', onClick: () => actions.unavailability(w, demo.assignments) },
                        { label: 'Déclarer une disponibilité', icon: 'event_available', variant: 'primary', onClick: () => declare() },
                    ]}
                />
            )}

            <div data-component="AvailabilityCalendar" style={{ border: '1px solid var(--c-line)', borderRadius: 'var(--radius-xl)', padding: 'var(--space-4)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', flexWrap: 'wrap', marginBottom: 'var(--space-3)' }}>
                    <Button icon="chevron_left" size="sm" variant="secondary" aria-label="Mois précédent" onClick={() => move(-1)} />
                    <b style={{ fontSize: 'var(--fs-xl)', minWidth: 170, textAlign: 'center' }}>{title}</b>
                    <Button icon="chevron_right" size="sm" variant="secondary" aria-label="Mois suivant" onClick={() => move(1)} />
                    <span style={{ flex: 1 }} />
                    <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>Cliquez sur un jour pour déclarer une disponibilité</span>
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,minmax(0,1fr))', gap: 'var(--space-1_5)' }}>
                    {['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'].map((d) => (
                        <div key={d} style={{ fontSize: 'var(--fs-xs)', fontWeight: 'var(--fw-bold)', color: 'var(--c-muted)', textTransform: 'uppercase', textAlign: 'center', padding: 'var(--space-1) 0' }}>
                            {d}
                        </div>
                    ))}
                    {Array.from({ length: offset }, (_, i) => (
                        <div key={`blank-${i}`} />
                    ))}
                    {Array.from({ length: daysInMonth }, (_, i) => {
                        const iso = `${year}-${pad(mon)}-${pad(i + 1)}`;
                        const info = dayInfo(iso);
                        const [bg, fg, short] = DAY_TYPES[info.type] ?? DAY_TYPES.Disponible;
                        const label = `${formatDate(iso)} · ${info.type}${info.assignment ? ` · ${info.assignment.site}` : ''}${info.period?.reason ? ` · ${info.period.reason}` : ''}${info.conflict ? ' · conflit' : ''}`;
                        const border = info.conflict ? 'var(--c-danger)' : iso === today ? 'var(--c-primary)' : info.type === 'Absence déclarée' ? 'var(--c-danger-fg)' : 'transparent';
                        return (
                            <button
                                key={iso}
                                type="button"
                                disabled={archived}
                                onClick={() => declare(iso, iso)}
                                aria-label={label}
                                title={label}
                                style={{ position: 'relative', minHeight: 62, padding: 'var(--space-1_5)', borderRadius: 'var(--radius-md)', border: `1.5px solid ${border}`, background: bg, color: fg, textAlign: 'left', cursor: archived ? 'default' : 'pointer', display: 'flex', flexDirection: 'column', justifyContent: 'space-between', gap: 2, minWidth: 0 }}
                            >
                                <span style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <b style={{ fontSize: 'var(--fs-sm)' }}>{i + 1}</b>
                                    {info.conflict && <Icon name="warning" size="var(--icon-xs)" color="var(--c-danger)" />}
                                </span>
                                <span style={{ fontSize: 'var(--fs-2xs)', lineHeight: 1.2, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{short}</span>
                            </button>
                        );
                    })}
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2) var(--space-4)', marginTop: 'var(--space-3)' }}>
                    {Object.entries(DAY_TYPES).map(([label, [bg]]) => (
                        <span key={label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1_5)', fontSize: 'var(--fs-sm)', color: 'var(--c-ink-2)' }}>
                            <span style={{ width: 12, height: 12, borderRadius: 'var(--radius-xs)', background: bg, border: `1.5px solid ${label === 'Absence déclarée' ? 'var(--c-danger-fg)' : bg}` }} />
                            {label}
                        </span>
                    ))}
                </div>
            </div>

            <DataTable
                icon="date_range"
                title="Périodes déclarées"
                count={`${periods.length} période(s)`}
                columns={['Période', 'Type', 'Motif', 'Zone', 'Commentaire']}
                empty={{ icon: 'event_available', title: 'Aucune période déclarée', text: 'L’ouvrier est considéré disponible hors affectations et dimanches.' }}
                rows={periods.map((p) => ({
                    key: String(p.id),
                    cells: [
                        <TextCell strong>
                            {formatDate(p.startsOn)} → {formatDate(p.endsOn)}
                        </TextCell>,
                        <StatusBadge label={p.type.label} tone={p.type.tone} />,
                        <TextCell>{p.reason || '—'}</TextCell>,
                        <LocationCell value={p.zone || '—'} />,
                        <TextCell muted={!p.comment}>{p.comment || '—'}</TextCell>,
                    ],
                    actions: archived
                        ? []
                        : [
                              {
                                  icon: 'delete',
                                  label: 'Supprimer',
                                  onClick: () => ui.confirm({ tone: 'danger', icon: 'delete', title: 'Supprimer cette période ?', ok: 'Supprimer', onConfirm: () => router.delete(`${base}/periodes/${p.id}`, { preserveScroll: true }) }),
                              },
                          ],
                }))}
            />
        </div>
    );
}
