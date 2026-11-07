const INPUT = { height: 'var(--control-sm)', padding: '0 var(--space-3)', border: '1px solid var(--c-line-strong)', borderRadius: 'var(--radius-md)', background: 'var(--c-surface)', font: 'inherit', fontSize: 'var(--fs-sm)' };

type Props = { from: string; to: string; onChange: (period: { du: string; au: string }) => void };

/** Période d’analyse : du… au…, le mois en cours par défaut. */
export default function PeriodFilter({ from, to, onChange }: Props) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
            <span style={{ color: 'var(--c-muted)', fontSize: 'var(--fs-sm)' }}>Du</span>
            <input type="date" className="och-input" aria-label="Date de début" value={from} max={to} onChange={(e) => e.target.value && onChange({ du: e.target.value, au: to })} style={INPUT} />
            <span style={{ color: 'var(--c-muted)', fontSize: 'var(--fs-sm)' }}>au</span>
            <input type="date" className="och-input" aria-label="Date de fin" value={to} min={from} onChange={(e) => e.target.value && onChange({ du: from, au: e.target.value })} style={INPUT} />
        </div>
    );
}
