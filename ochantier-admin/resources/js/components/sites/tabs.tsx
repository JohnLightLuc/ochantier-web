import { RelationTable } from '@/components/promoters/RelationTabs';
import { TextCell } from '@/components/list/Table';
import { Block, KeyValues } from '@/components/workers/blocks';
import { formatDate } from '@/lib/format';
import type { Site } from '@/types/sites';

/** Vue d’ensemble : informations, calendrier, contacts et besoins. */
export function OverviewTab({ site: s }: { site: Site }) {
    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,360px),1fr))', gap: 'var(--space-4)' }}>
            <Block icon="info" title="Informations">
                <KeyValues
                    items={[
                        ['Nature des travaux', s.workType],
                        ['Type de construction', s.type],
                        ['Référence interne', s.internalReference],
                        ['Adresse', s.address],
                        ['Accès', s.access],
                        ['Description', s.description],
                    ]}
                />
            </Block>
            <Block icon="event" title="Calendrier">
                <KeyValues
                    items={[
                        ['Début prévu', s.startsOn ? formatDate(s.startsOn) : null],
                        ['Fin prévue', s.endsOn ? formatDate(s.endsOn) : null],
                        ['Durée', s.durationDays ? `${s.durationDays} jours` : null],
                        ['Horaires', s.workHours],
                        ['Rayon de pointage', s.geofence ? `${s.geofence} m` : null],
                        ['Clôturé le', s.closedAt ? formatDate(s.closedAt) : null],
                    ]}
                />
            </Block>
            <Block icon="contacts" title="Contacts">
                <KeyValues
                    items={[
                        ['Promoteur', s.promoter],
                        ['Contact', s.contact.full_name],
                        ['Téléphone', s.contact.phone],
                        ['E-mail', s.contact.email],
                        ['Responsable', s.responsible?.full_name],
                        ['Téléphone du responsable', s.responsible?.phone],
                    ]}
                />
            </Block>
            <Block icon="engineering" title="Besoins" count={`${s.assigned} / ${s.required} affectés`}>
                <RelationTable
                    columns={['Métier', 'Effectif', 'Début', 'Statut']}
                    empty={{ icon: 'engineering', title: 'Aucun besoin', text: 'Le promoteur n’a pas encore publié de besoin.' }}
                    rows={s.needs.map((n) => ({ key: n.id, cells: [<TextCell strong>{n.trade ?? '—'}</TextCell>, <TextCell>{n.headcount}</TextCell>, <TextCell muted={!n.startsOn}>{n.startsOn ? formatDate(n.startsOn) : '—'}</TextCell>, <TextCell>{n.status}</TextCell>] }))}
                />
            </Block>
        </div>
    );
}
