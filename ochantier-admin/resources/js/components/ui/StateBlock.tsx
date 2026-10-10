import Icon from './Icon';

export type StateKind = 'loading' | 'empty' | 'nodata' | 'error' | 'forbidden' | 'success' | 'partial' | 'missing' | 'expired';

type Props = {
    kind?: StateKind;
    title?: string;
    text?: string;
    icon?: string;
    actionLabel?: string;
    onAction?: () => void;
    compact?: boolean;
    minHeight?: string;
};

const KINDS: Record<StateKind, [string, string, string, string]> = {
    loading: ['', 'primary', 'Chargement…', 'Récupération des données en cours.'],
    empty: ['search_off', 'neutral', 'Aucun résultat', 'Aucun élément ne correspond à vos critères. Modifiez la recherche ou les filtres.'],
    nodata: ['inbox', 'neutral', 'Aucune donnée', 'Rien à afficher pour le moment.'],
    error: ['cloud_off', 'danger', 'Impossible de charger les données', 'Une erreur est survenue. Vérifiez votre connexion puis réessayez.'],
    forbidden: ['lock', 'purple', 'Accès restreint', 'Votre profil ne permet pas de consulter cette information.'],
    success: ['task_alt', 'success', 'Opération réussie', ''],
    partial: ['rule', 'warning', 'Données partielles', 'Certaines informations sont encore en cours de synchronisation.'],
    missing: ['upload_file', 'warning', 'Document KYC manquant', 'Aucun fichier n’a été déposé pour ce document.'],
    expired: ['event_busy', 'orange', 'Document KYC expiré', 'Une version à jour doit être demandée au promoteur.'],
};

const TINTED: StateKind[] = ['error', 'success', 'partial', 'missing', 'expired', 'forbidden'];

/** Bloc d’état : chargement, vide, erreur, accès limité. */
export default function StateBlock({ kind = 'nodata', title, text, icon, actionLabel, onAction, compact, minHeight }: Props) {
    const [defaultIcon, tone, defaultTitle, defaultText] = KINDS[kind];
    const tinted = TINTED.includes(kind);
    const body = text ?? defaultText;

    return (
        <div
            role={kind === 'error' || kind === 'forbidden' ? 'alert' : 'status'}
            style={{
                display: 'flex',
                flexDirection: compact ? 'row' : 'column',
                alignItems: 'center',
                justifyContent: 'center',
                gap: compact ? 'var(--space-3)' : 'var(--space-2)',
                textAlign: compact ? 'left' : 'center',
                padding: compact ? 'var(--space-3) var(--space-4)' : 'var(--space-8) var(--space-5)',
                minHeight,
                border: `1px ${tinted ? 'solid' : 'dashed'} ${tinted ? 'transparent' : 'var(--c-line-strong)'}`,
                borderRadius: 'var(--radius-xl)',
                background: tinted ? `var(--c-${tone}-bg)` : 'var(--c-surface)',
                color: 'var(--c-ink)',
            }}
        >
            {kind === 'loading' ? (
                <span style={{ width: 'var(--icon-lg)', height: 'var(--icon-lg)', flex: 'none', borderRadius: 'var(--radius-round)', border: '3px solid var(--c-primary-200)', borderTopColor: 'var(--c-primary)', animation: 'och-spin .8s linear infinite' }} />
            ) : (
                <span
                    style={{
                        width: compact ? 'var(--control-sm)' : 'var(--control-lg)',
                        height: compact ? 'var(--control-sm)' : 'var(--control-lg)',
                        flex: 'none',
                        borderRadius: 'var(--radius-round)',
                        display: 'grid',
                        placeItems: 'center',
                        background: tinted ? 'var(--c-surface)' : `var(--c-${tone}-bg)`,
                    }}
                >
                    <Icon name={icon || defaultIcon} size={compact ? 'var(--icon-sm)' : 'var(--icon-md)'} color={`var(--c-${tone}-fg)`} />
                </span>
            )}
            <div style={{ flex: compact ? 1 : undefined, minWidth: 0 }}>
                <div style={{ fontSize: compact ? 'var(--fs-md)' : 'var(--fs-lg)', fontWeight: 'var(--fw-bold)' }}>{title || defaultTitle}</div>
                {body && <div style={{ marginTop: 'var(--space-1)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', lineHeight: 'var(--lh-normal)', textWrap: 'pretty' }}>{body}</div>}
            </div>
            {actionLabel && (
                <button
                    type="button"
                    onClick={onAction}
                    className="och-hover-line"
                    style={{ flex: 'none', height: 'var(--control-xs)', padding: '0 var(--space-3)', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', color: 'var(--c-ink)', cursor: 'pointer', whiteSpace: 'nowrap' }}
                >
                    {actionLabel}
                </button>
            )}
        </div>
    );
}
