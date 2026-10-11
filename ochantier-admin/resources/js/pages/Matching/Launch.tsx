import Button from '@/components/ui/Button';
import Field, { SelectInput } from '@/components/ui/Field';
import Icon from '@/components/ui/Icon';
import { useAdminUi } from '@/contexts/AdminUiContext';
import type { MatchingConfig } from '@/types/matching';
import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

type Offer = { id: number; label: string; reference: string; site: string; promoter: string; zone: string; trade: string; urgent: boolean; all: number; excluded: number; unverified: number; partial: number };
type Result = { reference: string; status: string; needs: number; recommendations: number; without_profile: number; excluded: number; warnings: number; errors: number; duration: number };
type Props = { offers: Offer[]; config: MatchingConfig; prefill: { portee?: string; cible?: string }; result: Result | null };

const STEPS: [string, string, string][] = [
    ['Périmètre', 'filter_center_focus', 'Choisissez les besoins à analyser.'],
    ['Paramètres', 'tune', 'Paramètres du moteur définis dans Paramètres (lecture seule).'],
    ['Prévisualisation', 'preview', 'Estimation avant lancement.'],
    ['Lancement', 'play_circle', 'Suivi du traitement.'],
    ['Résultat', 'task_alt', 'Synthèse du cycle.'],
];

const SCOPES = ['Un besoin précis', 'Plusieurs besoins', 'Un chantier', 'Un promoteur', 'Une zone', 'Un métier', 'Les besoins urgents', 'Tous les besoins éligibles'];
const STATES = ['En attente', 'Initialisation', 'Analyse des besoins', 'Recherche des profils', 'Vérification de l’éligibilité', 'Calcul des scores', 'Classement', 'Finalisation'];

/** Assistant de lancement d’un cycle de Matching : aucune affectation automatique. */
export default function MatchingLaunch({ offers, config, prefill, result }: Props) {
    const ui = useAdminUi();
    const [step, setStep] = useState(result ? 4 : 0);
    const [scope, setScope] = useState(prefill.portee === 'besoin' ? 'Un besoin précis' : 'Tous les besoins éligibles');
    const [target, setTarget] = useState(prefill.cible ?? '');
    const [multi, setMulti] = useState<number[]>([]);
    const [tried, setTried] = useState<Record<number, boolean>>({});
    const [run, setRun] = useState<number | null>(null);
    const timer = useRef<number | undefined>(undefined);
    const started = useRef(0);

    useEffect(() => () => window.clearInterval(timer.current), []);
    useEffect(() => {
        if (result) {
            setStep(4);
            setRun(null);
        }
    }, [result]);

    const uniq = (values: string[]) => [...new Set(values)].sort((a, b) => a.localeCompare(b, 'fr'));
    const targets: Record<string, { value: string; label: string }[]> = {
        'Un besoin précis': offers.map((o) => ({ value: String(o.id), label: o.label })),
        'Un chantier': uniq(offers.map((o) => o.site)).map((v) => ({ value: v, label: v })),
        'Un promoteur': uniq(offers.map((o) => o.promoter)).map((v) => ({ value: v, label: v })),
        'Une zone': uniq(offers.map((o) => o.zone)).map((v) => ({ value: v, label: v })),
        'Un métier': uniq(offers.map((o) => o.trade)).map((v) => ({ value: v, label: v })),
    };
    const inScope = offers.filter((o) =>
        scope === 'Un besoin précis' ? String(o.id) === target
        : scope === 'Plusieurs besoins' ? multi.includes(o.id)
        : scope === 'Un chantier' ? o.site === target
        : scope === 'Un promoteur' ? o.promoter === target
        : scope === 'Une zone' ? o.zone === target
        : scope === 'Un métier' ? o.trade === target
        : scope === 'Les besoins urgents' ? o.urgent
        : true,
    );

    const errors = (i: number): Record<string, string> => {
        const e: Record<string, string> = {};
        if (i === 0) {
            if (targets[scope] && !target) e.target = 'Ce champ est obligatoire.';
            if (scope === 'Plusieurs besoins' && !multi.length) e.multi = 'Sélectionnez au moins un besoin.';
            if (!e.target && !e.multi && !inScope.length) e.scope = 'Aucun besoin éligible dans ce périmètre.';
        }
        if (i === 1 && !config.ok) e.config = 'Configuration du moteur de Matching requise dans Paramètres.';
        return e;
    };
    const shown = tried[step] ? errors(step) : {};

    const goTo = (i: number) => {
        if (run !== null && i !== 3) return ui.toast('Traitement en cours…', { tone: 'info' });
        if (i === 4 && !result) return;
        if (i > step) {
            for (let k = step; k < i; k++) {
                const e = errors(k);
                if (Object.keys(e).length) {
                    setTried((t) => ({ ...t, [k]: true }));
                    setStep(k);
                    return ui.toast(e.config ?? e.scope ?? 'Complétez le périmètre.', { tone: 'error' });
                }
            }
        }
        setStep(i);
        window.scrollTo({ top: 0 });
    };

    const payload = () => ({ scope, target: targets[scope] ? target : null, multi: scope === 'Plusieurs besoins' ? multi : [] });

    const start = () => {
        started.current = Date.now();
        let i = 0;
        setRun(0);
        window.clearInterval(timer.current);
        timer.current = window.setInterval(() => {
            i += 1;
            setRun(i);
            if (i < STATES.length) return;
            window.clearInterval(timer.current);
            router.post('/matching/lancement', { ...payload(), duration: Math.max(2, Math.round((Date.now() - started.current) / 1000)) }, { onError: () => setRun(null) });
        }, 420);
    };

    const cancelRun = () => {
        window.clearInterval(timer.current);
        router.post('/matching/lancement/annulation', payload(), { onFinish: () => setRun(null) });
    };

    const sum = (key: 'all' | 'excluded' | 'unverified' | 'partial') => inScope.reduce((a, o) => a + o[key], 0);
    const items = (list: [string, ReactNode][]) => list.map(([k, v]) => <Item key={k} k={k} v={v} />);

    return (
        <>
            <Head title="Lancer un matching" />

            <div data-component="Stepper" style={{ background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-4) var(--space-5)' }}>
                <b style={{ fontSize: 'var(--fs-md)' }}>
                    Étape {step + 1} sur {STEPS.length} · {STEPS[step][0]}
                </b>
                <div role="progressbar" aria-valuenow={(step + 1) * 20} aria-valuemin={0} aria-valuemax={100} style={{ height: 6, borderRadius: 'var(--radius-pill)', background: 'var(--c-line-soft)', overflow: 'hidden', margin: 'var(--space-2_5) 0 var(--space-3)' }}>
                    <div style={{ height: '100%', width: `${(step + 1) * 20}%`, background: 'var(--c-primary-500)' }} />
                </div>
                <ol style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(140px,1fr))', gap: 'var(--space-2)' }}>
                    {STEPS.map(([label], i) => (
                        <li key={label}>
                            <button type="button" onClick={() => goTo(i)} aria-current={i === step ? 'step' : undefined} className="och-hover-menu" style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: 'var(--space-2)', border: 0, borderRadius: 'var(--radius-md)', background: i === step ? 'var(--c-primary-50)' : 'transparent', cursor: 'pointer', textAlign: 'left' }}>
                                <span style={{ width: 28, height: 28, flex: 'none', borderRadius: 'var(--radius-round)', display: 'grid', placeItems: 'center', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-bold)', background: i < step ? 'var(--c-primary)' : i === step ? 'var(--c-surface)' : 'var(--c-surface-3)', color: i < step ? 'var(--c-on-dark)' : i === step ? 'var(--c-primary)' : 'var(--c-muted)', border: `1.5px solid ${i <= step ? 'var(--c-primary)' : 'var(--c-line-strong)'}` }}>
                                    {i < step ? <Icon name="check" size="var(--icon-xs)" /> : i + 1}
                                </span>
                                <span style={{ fontSize: 'var(--fs-sm)', fontWeight: i === step ? 700 : 500 }}>{label}</span>
                            </button>
                        </li>
                    ))}
                </ol>
            </div>

            {(Object.keys(shown).length > 0 || (step === 1 && !config.ok)) && (
                <div role="alert" style={{ display: 'flex', gap: 'var(--space-3)', alignItems: 'center', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-danger-bg)', color: 'var(--c-danger-fg)' }}>
                    <Icon name="error" />
                    <b style={{ fontSize: 'var(--fs-md)' }}>{step === 1 && !config.ok ? 'Configuration du moteur de Matching requise dans Paramètres.' : (shown.scope ?? `${Object.keys(shown).length} champ(s) à corriger.`)}</b>
                </div>
            )}

            <Section step={step} desc={step === 3 ? (result ? `Traitement ${result.status.toLowerCase()}.` : run !== null ? `Traitement en cours : ${STATES[Math.min(run, STATES.length - 1)]}…` : 'Cliquez sur « Lancer le matching ».') : undefined}>
                {step === 0 && (
                    <>
                        <Field label="Périmètre" required>
                            <SelectInput value={scope} onChange={(e) => (setScope(e.target.value), setTarget(''), setMulti([]))} aria-label="Périmètre" options={SCOPES.map((s) => ({ value: s, label: s }))} />
                        </Field>
                        {targets[scope] && (
                            <Field label={scope.replace(/^Une? /, '').replace(/^./, (c) => c.toUpperCase())} required error={shown.target}>
                                <SelectInput value={target} onChange={(e) => setTarget(e.target.value)} invalid={!!shown.target} aria-label="Cible" options={[{ value: '', label: 'Sélectionner' }, ...targets[scope]]} />
                            </Field>
                        )}
                        {scope === 'Plusieurs besoins' && (
                            <Field label="Besoins" span="full" error={shown.multi}>
                                <span style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-1_5)' }}>
                                    {offers.map((o) => {
                                        const on = multi.includes(o.id);
                                        return (
                                            <button key={o.id} type="button" aria-pressed={on} onClick={() => setMulti(on ? multi.filter((x) => x !== o.id) : [...multi, o.id])} style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1)', height: 30, padding: '0 var(--space-3)', borderRadius: 'var(--radius-pill)', border: `1.5px solid ${on ? 'var(--c-primary-400)' : 'var(--c-line-strong)'}`, background: on ? 'var(--c-primary-50)' : 'var(--c-surface)', color: on ? 'var(--c-primary)' : 'var(--c-ink-2)', fontSize: 'var(--fs-sm)', fontWeight: 'var(--fw-semibold)', cursor: 'pointer' }}>
                                                {on && <Icon name="check" size="var(--icon-xs)" />}
                                                {o.reference} · {o.site}
                                            </button>
                                        );
                                    })}
                                </span>
                            </Field>
                        )}
                        <Item k="Besoins éligibles dans le périmètre" v={shown.scope ?? String(inScope.length)} full />
                    </>
                )}
                {step === 1 &&
                    items([
                        ['Version de l’algorithme', config.algorithm],
                        ['Pondérations', `${config.weights_version} · total ${config.sum} %${config.ok ? '' : ' · INVALIDE'}`],
                        ...Object.values(config.weights).map(([label, w]) => [`· ${label}`, `${w} %`] as [string, string]),
                        ['Seuils', Object.entries(config.thresholds).map(([l, v]) => `${l} ≥ ${v}`).join(' · ')],
                        ['Critères bloquants', 'Statut, identité, disponibilité, planning, distance, rémunération, documents, litiges, doublons, refus, besoin actif'],
                        ['Filtres', 'Besoins validés, publiés, ouverts, avec postes restants'],
                        ['Rayon géographique', `${config.radius} km prioritaire · ${config.radius_max} km bloquant`],
                        ['Tolérance budgétaire', `${config.tolerance} % · ${config.tolerance_max} % bloquant`],
                        ['Période de disponibilité', 'Période de chaque besoin'],
                        ['Limite de profils par besoin', String(config.limit)],
                        ['Source', 'Valeurs définies dans Paramètres'],
                    ])}
                {step === 2 &&
                    items([
                        ['Besoins analysés', String(inScope.length)],
                        ['Ouvriers potentiels', String(sum('all'))],
                        ['Profils exclus', String(sum('excluded'))],
                        ['Données manquantes', `${sum('unverified')} profil(s) non vérifié(s)`],
                        ['Anomalies', `${sum('partial')} conflit(s) partiel(s) de planning`],
                        ['Temps estimé', `${Math.max(2, Math.round(inScope.length * 1.4))} s`],
                    ])}
                {step === 3 && (
                    <>
                        {STATES.map((label, i) => {
                            const done = (run !== null && i < run) || (!!result && result.status !== 'Annulé');
                            const current = run === i;
                            return <Item key={label} k={`${done ? '✓' : current ? '…' : '○'} ${label}`} v={done ? 'Terminé' : current ? 'En cours' : result?.status === 'Annulé' ? 'Annulé' : 'À venir'} />;
                        })}
                        {result && <Item k="Statut final" v={result.status} full />}
                    </>
                )}
                {step === 4 &&
                    result &&
                    items([
                        ['Cycle', result.reference],
                        ['Statut', result.status],
                        ['Besoins traités', String(result.needs)],
                        ['Recommandations produites', String(result.recommendations)],
                        ['Besoins sans profil', String(result.without_profile)],
                        ['Profils exclus', String(result.excluded)],
                        ['Avertissements', String(result.warnings)],
                        ['Erreurs', String(result.errors)],
                        ['Durée du traitement', `${result.duration} s`],
                    ])}
            </Section>

            <div style={{ position: 'sticky', bottom: 0, zIndex: 5, display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', padding: 'var(--space-4) var(--space-5)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', boxShadow: 'var(--shadow-md)' }}>
                <span style={{ flex: 1 }} />
                <Button label="Annuler" variant="ghost" onClick={() => (run !== null && !result ? ui.toast('Annulez d’abord le traitement en cours.', { tone: 'error' }) : router.visit('/matching'))} />
                {step === 1 && !config.ok && <Button label="Ouvrir Paramètres" icon="settings" variant="secondary" onClick={() => router.visit('/parametres')} />}
                {step === 3 && run !== null && !result && <Button label="Annuler le traitement" icon="cancel" variant="secondary" onClick={cancelRun} />}
                {step === 4 && <Button label="Consulter les résultats" icon="list" variant="secondary" onClick={() => router.visit('/matching/resultats')} />}
                {step > 0 && step < 4 && <Button label="Précédent" icon="arrow_back" variant="secondary" onClick={() => goTo(step - 1)} />}
                {step < 3 && <Button label="Suivant" iconRight="arrow_forward" variant="primary" onClick={() => goTo(step + 1)} />}
                {step === 3 && <Button label={result ? 'Voir le résultat' : run !== null ? 'Traitement en cours…' : 'Lancer le matching'} icon="play_circle" variant="primary" disabled={run !== null && !result} onClick={() => (result ? goTo(4) : start())} />}
                {step === 4 && <Button label="Terminer" icon="check" variant="primary" onClick={() => router.visit('/matching')} />}
            </div>
        </>
    );
}

function Item({ k, v, full }: { k: string; v: ReactNode; full?: boolean }) {
    return (
        <div style={{ gridColumn: full ? '1 / -1' : 'auto', display: 'flex', flexDirection: 'column', gap: 2, padding: 'var(--space-2) 0', borderTop: '1px solid var(--c-line-soft)', minWidth: 0 }}>
            <span style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{k}</span>
            <b style={{ fontWeight: 'var(--fw-semibold)', overflowWrap: 'anywhere' }}>{v}</b>
        </div>
    );
}

function Section({ step, desc, children }: { step: number; desc?: string; children: ReactNode }) {
    const [label, icon, description] = STEPS[step];

    return (
        <section style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,260px),1fr))', gap: 'var(--space-6)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-2xl)', padding: 'var(--space-6)' }}>
            <div style={{ maxWidth: 300 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                    <Icon name={icon} size="var(--icon-md)" color="var(--c-primary)" />
                    <h3 style={{ margin: 0, fontSize: 'var(--fs-xl)' }}>{label}</h3>
                </div>
                <p style={{ margin: 'var(--space-1_5) 0 0', fontSize: 'var(--fs-md)', color: 'var(--c-muted)' }}>{desc ?? description}</p>
            </div>
            <div style={{ gridColumn: 'span 2', display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(min(100%,240px),1fr))', gap: 'var(--space-2) var(--space-4)', alignContent: 'start' }}>{children}</div>
        </section>
    );
}
