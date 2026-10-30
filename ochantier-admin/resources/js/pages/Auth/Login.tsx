import Button from '@/components/ui/Button';
import Icon from '@/components/ui/Icon';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type CSSProperties, type FormEvent, type ReactNode } from 'react';

type Props =
    | { step: 'login'; email: string | null; status: string | null }
    | { step: 'otp'; maskedEmail: string; resendIn: number; status: string | null }
    | { step: 'forgot' }
    | { step: 'sent'; sentTo: string }
    | { step: 'reset'; token: string; email: string }
    | { step: 'change' };

const HERO = 'https://images.unsplash.com/photo-1694521787193-9293daeddbaa?fm=jpg&q=70&w=1600&auto=format&fit=crop';

const FEATURES = [
    { icon: 'verified_user', label: 'Données\nsécurisées' },
    { icon: 'monitoring', label: 'Suivi en\ntemps réel' },
    { icon: 'groups', label: 'Accès\ncontrôlé' },
];

const TITLES = { login: 'Connexion', otp: 'Vérification en deux étapes', forgot: 'Mot de passe oublié', sent: 'Lien envoyé', reset: 'Nouveau mot de passe', change: 'Nouveau mot de passe' };

const PASSWORD_HINT = 'Au moins 12 caractères, avec une majuscule, une minuscule, un chiffre et un symbole.';

/** Authentification : connexion, vérification OTP, mot de passe oublié, réinitialisation et mot de passe provisoire. */
export default function Login(props: Props) {
    return (
        <>
            <Head title={TITLES[props.step]} />
            <div data-component="AuthenticationLayout" style={{ minHeight: '100vh', display: 'flex', flexWrap: 'wrap', background: 'var(--c-surface)' }}>
                <BrandPanel />
                <div data-component="AuthFormPanel" style={{ flex: '1 1 480px', minHeight: '100vh', display: 'flex', flexDirection: 'column', alignItems: 'center', padding: 'var(--space-6) var(--space-8)', background: 'var(--c-surface-2)' }}>
                    <div style={{ alignSelf: 'flex-end', display: 'flex', alignItems: 'center', gap: 'var(--space-2)', height: 'var(--control-sm)', padding: '0 var(--space-3)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)', fontWeight: 'var(--fw-semibold)' }}>
                        <Icon name="language" color="var(--c-ink-2)" />
                        FR
                    </div>
                    <div style={{ flex: 1, display: 'flex', alignItems: 'center', width: '100%', justifyContent: 'center', padding: 'var(--space-6) 0' }}>
                        <div data-component="AuthCard" style={{ width: '100%', maxWidth: 'var(--auth-card-w)', background: 'var(--c-surface)', border: '1px solid var(--c-line)', borderRadius: 'var(--radius-3xl)', boxShadow: 'var(--shadow-lg)', padding: 'var(--space-12) var(--space-12) var(--space-10)' }}>
                            {props.step === 'login' && <LoginForm email={props.email} status={props.status} />}
                            {props.step === 'otp' && <OtpForm maskedEmail={props.maskedEmail} resendIn={props.resendIn} status={props.status} />}
                            {props.step === 'forgot' && <ForgotForm />}
                            {props.step === 'sent' && <SentNotice email={props.sentTo} />}
                            {props.step === 'reset' && <ResetForm token={props.token} email={props.email} />}
                            {props.step === 'change' && <ChangeForm />}
                            <p style={{ margin: 'var(--space-6) 0 0', textAlign: 'center', fontSize: 'var(--fs-base)', color: 'var(--c-muted)' }}>
                                Besoin d’aide ? <a href="mailto:support@ochantier.ci" style={{ fontWeight: 'var(--fw-semibold)' }}>Contacter le support</a>
                            </p>
                        </div>
                    </div>
                    <div style={{ fontSize: 'var(--fs-sm)', color: 'var(--c-muted)', display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap', justifyContent: 'center' }}>
                        <span>© 2026 O’CHANTIER</span>
                        <span>·</span>
                        <span>Confidentialité</span>
                        <span>·</span>
                        <span>Conditions</span>
                    </div>
                </div>
            </div>
        </>
    );
}

function BrandPanel() {
    return (
        <aside data-component="AuthBrandPanel" style={{ flex: '1 1 520px', minHeight: '100vh', position: 'relative', overflow: 'hidden', background: 'var(--c-primary-strong)', color: 'var(--c-on-dark)', display: 'flex', flexDirection: 'column' }}>
            <img src={HERO} alt="" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }} />
            <div style={{ position: 'absolute', inset: 0, pointerEvents: 'none', background: 'linear-gradient(180deg,rgba(9,52,40,.97) 0%,rgba(9,52,40,.88) 34%,rgba(9,52,40,.25) 60%,rgba(9,52,40,.35) 74%,rgba(8,45,35,.96) 100%)' }} />
            <div style={{ position: 'absolute', right: -180, top: -120, width: 560, height: 900, borderRadius: 'var(--radius-round)', border: '1.5px solid var(--c-accent)', opacity: 0.5, pointerEvents: 'none' }} />
            <div style={{ position: 'relative', display: 'flex', flexDirection: 'column', flex: 1, padding: 'var(--space-12) var(--space-14) var(--space-10)' }}>
                <div style={{ display: 'inline-flex', flexDirection: 'column', alignItems: 'center', alignSelf: 'flex-start' }}>
                    <div style={{ fontWeight: 'var(--fw-black)', fontSize: 'var(--fs-logo-lg)', lineHeight: 1, letterSpacing: '-.5px' }}>
                        <span style={{ color: 'var(--c-accent)' }}>O’</span>
                        <span style={{ color: 'var(--c-brand-green)' }}>CHANTIER</span>
                    </div>
                    <div style={{ fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-sm)', letterSpacing: '.45em', marginTop: 'var(--space-2)', paddingLeft: '.45em' }}>ADMIN</div>
                </div>
                <h1 style={{ margin: 'var(--space-12) 0 0', fontWeight: 'var(--fw-bold)', fontSize: 'var(--fs-display)', lineHeight: 'var(--lh-tight)', letterSpacing: '-1px', maxWidth: 560, textWrap: 'balance' }}>
                    Pilotez vos chantiers avec <span style={{ color: 'var(--c-accent)' }}>sérénité</span>
                </h1>
                <p style={{ margin: 'var(--space-4) 0 0', fontSize: 'var(--fs-xl)', lineHeight: 'var(--lh-normal)', maxWidth: 460, color: 'var(--c-on-dark-muted)', textWrap: 'pretty' }}>
                    Centralisez vos équipes, vos opérations et vos paiements depuis un espace sécurisé.
                </p>
                <div style={{ flex: 1, minHeight: 200 }} />
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-5)', alignItems: 'center' }}>
                    {FEATURES.map((f) => (
                        <div key={f.icon} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', paddingRight: 'var(--space-5)', borderRight: '1px solid var(--c-on-dark-line)' }}>
                            <div style={{ width: 'var(--control-lg)', height: 'var(--control-lg)', borderRadius: 'var(--radius-round)', border: '1.5px solid var(--c-accent)', background: 'rgba(8,45,35,.55)', display: 'grid', placeItems: 'center' }}>
                                <Icon name={f.icon} size="var(--icon-md)" color="var(--c-accent)" />
                            </div>
                            <div style={{ fontSize: 'var(--fs-base)', lineHeight: 'var(--lh-snug)', whiteSpace: 'pre-line' }}>{f.label}</div>
                        </div>
                    ))}
                </div>
                <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 'var(--space-4)', flexWrap: 'wrap', marginTop: 'var(--space-8)' }}>
                    <div>
                        <div style={{ width: 'var(--space-14)', height: 3, background: 'var(--c-accent)', marginBottom: 'var(--space-4)' }} />
                        <div style={{ fontSize: 'var(--fs-base)', letterSpacing: '.28em' }}>Des talents pour bâtir demain</div>
                    </div>
                    <a href="https://unsplash.com/@glenovbrankovic" target="_blank" rel="noreferrer" style={{ fontSize: 'var(--fs-xs)', color: 'var(--c-on-dark-muted)', textDecoration: 'none' }}>
                        Photo : Glenov Brankovic / Unsplash
                    </a>
                </div>
            </div>
        </aside>
    );
}

function CardHeader({ icon, iconTone = 'primary', title, children }: { icon?: string; iconTone?: 'primary' | 'success'; title: string; children: ReactNode }) {
    return (
        <>
            {icon && (
                <div style={{ width: 'var(--control-lg)', height: 'var(--control-lg)', borderRadius: 'var(--radius-round)', background: iconTone === 'success' ? 'var(--c-success-bg)' : 'var(--c-primary-100)', display: 'grid', placeItems: 'center', margin: '0 auto var(--space-5)' }}>
                    <Icon name={icon} size="var(--icon-md)" color={iconTone === 'success' ? 'var(--c-success-fg)' : 'var(--c-primary-600)'} />
                </div>
            )}
            <h2 style={{ margin: 0, textAlign: 'center', fontWeight: 'var(--fw-bold)', fontSize: icon ? 'var(--fs-4xl)' : 'var(--fs-5xl)', letterSpacing: icon ? '-.4px' : '-.6px' }}>{title}</h2>
            <p style={{ margin: 'var(--space-2) 0 var(--space-8)', textAlign: 'center', fontSize: 'var(--fs-lg)', color: 'var(--c-muted)', textWrap: 'pretty' }}>{children}</p>
        </>
    );
}

function ErrorAlert({ message }: { message?: string }) {
    if (!message) return null;
    return (
        <div role="alert" style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'center', padding: 'var(--space-3) var(--space-4)', marginBottom: 'var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-danger-bg)', color: 'var(--c-danger-fg)', fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-medium)' }}>
            <Icon name="error" />
            {message}
        </div>
    );
}

function StatusAlert({ message }: { message?: string | null }) {
    if (!message) return null;
    return (
        <div role="status" style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'center', padding: 'var(--space-3) var(--space-4)', marginBottom: 'var(--space-4)', borderRadius: 'var(--radius-lg)', background: 'var(--c-success-bg)', color: 'var(--c-success-fg)', fontSize: 'var(--fs-md)', fontWeight: 'var(--fw-medium)' }}>
            <Icon name="check_circle" />
            {message}
        </div>
    );
}

const labelStyle: CSSProperties = { display: 'block', fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-base)', marginBottom: 'var(--space-2)' };
const inputStyle: CSSProperties = { flex: 1, border: 0, outline: 0, background: 'transparent', fontSize: 'var(--fs-lg)', minWidth: 0 };

function fieldStyle(invalid: boolean, paddingRight = 'var(--space-4)'): CSSProperties {
    return { display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', height: 'var(--control-lg)', padding: `0 ${paddingRight} 0 var(--space-4)`, border: `1.5px solid ${invalid ? 'var(--c-danger)' : 'var(--c-line-strong)'}`, borderRadius: 'var(--radius-lg)', background: 'var(--c-surface)' };
}

function LoginForm({ email, status }: { email: string | null; status: string | null }) {
    const form = useForm({ email: email ?? '', password: '', remember: email !== null });
    const [showPassword, setShowPassword] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/connexion', { onFinish: () => form.reset('password') });
    };

    return (
        <form onSubmit={submit} noValidate>
            <CardHeader title="Heureux de vous revoir">Connectez-vous à votre espace administrateur</CardHeader>
            {!form.hasErrors && <StatusAlert message={status} />}
            <ErrorAlert message={form.errors.email ?? form.errors.password} />

            <label htmlFor="email" style={labelStyle}>
                Adresse e-mail
            </label>
            <div style={fieldStyle(!!form.errors.email)}>
                <Icon name="mail" color="var(--c-muted)" outlined />
                <input id="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} type="email" autoComplete="username" placeholder="nom@entreprise.com" autoFocus={!email} style={inputStyle} />
            </div>

            <label htmlFor="password" style={{ ...labelStyle, margin: 'var(--space-5) 0 var(--space-2)' }}>
                Mot de passe
            </label>
            <div style={fieldStyle(!!form.errors.password, 'var(--space-2)')}>
                <Icon name="lock" color="var(--c-muted)" outlined />
                <input id="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} type={showPassword ? 'text' : 'password'} autoComplete="current-password" placeholder="••••••••••" autoFocus={!!email} style={inputStyle} />
                <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    aria-label={showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
                    className="och-hover-menu"
                    style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', border: 0, background: 'transparent', borderRadius: 'var(--radius-md)', display: 'grid', placeItems: 'center', cursor: 'pointer' }}
                >
                    <Icon name={showPassword ? 'visibility_off' : 'visibility'} color="var(--c-ink-2)" outlined />
                </button>
            </div>

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', margin: 'var(--space-4) 0 var(--space-6)', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
                <button
                    type="button"
                    role="checkbox"
                    aria-checked={form.data.remember}
                    onClick={() => form.setData('remember', !form.data.remember)}
                    style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2_5)', border: 0, background: 'transparent', padding: 0, cursor: 'pointer', fontSize: 'var(--fs-base)', color: 'var(--c-ink-2)' }}
                >
                    <span style={{ width: 22, height: 22, borderRadius: 'var(--radius-sm)', display: 'grid', placeItems: 'center', background: form.data.remember ? 'var(--c-primary)' : 'var(--c-surface)', border: `1.5px solid ${form.data.remember ? 'var(--c-primary)' : 'var(--c-line-hover)'}` }}>
                        {form.data.remember && <Icon name="check" size="var(--icon-xs)" color="var(--c-on-dark)" />}
                    </span>
                    Se souvenir de moi
                </button>
                <Link href="/mot-de-passe-oublie" style={{ fontWeight: 'var(--fw-semibold)', fontSize: 'var(--fs-base)' }}>
                    Mot de passe oublié ?
                </Link>
            </div>

            <Button type="submit" label="Se connecter" iconRight="arrow_forward" variant="primary" size="lg" full disabled={form.processing} />

            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', margin: 'var(--space-6) 0 var(--space-5)', color: 'var(--c-muted)', fontSize: 'var(--fs-md)' }}>
                <div style={{ flex: 1, height: 1, background: 'var(--c-line)' }} />
                <Icon name="lock" size="var(--icon-xs)" outlined />
                Connexion sécurisée
                <div style={{ flex: 1, height: 1, background: 'var(--c-line)' }} />
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-4) var(--space-5)', borderRadius: 'var(--radius-lg)', background: 'var(--c-primary-50)', fontSize: 'var(--fs-md)', color: 'var(--c-ink-2)' }}>
                <Icon name="verified_user" size="var(--icon-md)" color="var(--c-primary-600)" outlined />
                Vos accès sont protégés par une authentification renforcée.
            </div>
        </form>
    );
}

function OtpForm({ maskedEmail, resendIn, status }: { maskedEmail: string; resendIn: number; status: string | null }) {
    const form = useForm({ code: '' });
    const resend = useForm({});
    const [wait, setWait] = useState(resendIn);

    useEffect(() => setWait(resendIn), [resendIn]);
    useEffect(() => {
        if (wait <= 0) return;
        const timer = setTimeout(() => setWait(wait - 1), 1000);
        return () => clearTimeout(timer);
    }, [wait]);

    const resendCode = () => {
        form.reset('code');
        form.clearErrors();
        resend.post('/connexion/verification/renvoi', { onError: (errors) => form.setError('code', errors.code ?? '') });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (form.data.code.length < 6) {
            form.setError('code', 'Le code doit contenir 6 chiffres.');
            return;
        }
        form.post('/connexion/verification');
    };

    return (
        <form onSubmit={submit} noValidate>
            <CardHeader icon="shield_lock" title="Vérification en deux étapes">
                Saisissez le code à 6 chiffres envoyé à <b style={{ color: 'var(--c-ink)' }}>{maskedEmail}</b>.
            </CardHeader>
            {!form.hasErrors && <StatusAlert message={status} />}
            <ErrorAlert message={form.errors.code} />

            <label style={{ position: 'relative', display: 'grid', gridTemplateColumns: 'repeat(6,1fr)', gap: 'var(--space-2_5)', marginBottom: 'var(--space-6)', cursor: 'text' }}>
                {Array.from({ length: 6 }, (_, i) => {
                    const digit = form.data.code[i] ?? '';
                    const border = i === form.data.code.length ? 'var(--c-primary)' : digit ? 'var(--c-primary-400)' : 'var(--c-line-strong)';
                    return (
                        <span key={i} style={{ height: 'var(--control-lg)', borderRadius: 'var(--radius-lg)', border: `1.5px solid ${border}`, background: 'var(--c-surface)', display: 'grid', placeItems: 'center', fontSize: 'var(--fs-3xl)', fontWeight: 'var(--fw-bold)', fontVariantNumeric: 'tabular-nums' }}>
                            {digit}
                        </span>
                    );
                })}
                <input
                    value={form.data.code}
                    onChange={(e) => {
                        form.setData('code', e.target.value.replace(/\D/g, '').slice(0, 6));
                        form.clearErrors('code');
                    }}
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    aria-label="Code de vérification"
                    autoFocus
                    style={{ position: 'absolute', inset: 0, opacity: 0, border: 0, fontSize: 'var(--fs-lg)' }}
                />
            </label>

            <Button type="submit" label="Vérifier et continuer" iconRight="arrow_forward" variant="primary" size="lg" full disabled={form.processing} />

            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 'var(--space-3)', flexWrap: 'wrap', marginTop: 'var(--space-6)', fontSize: 'var(--fs-md)' }}>
                <Link href="/connexion" style={{ fontWeight: 'var(--fw-semibold)' }}>
                    ← Changer de compte
                </Link>
                <span style={{ color: 'var(--c-muted)' }}>
                    Pas reçu ?{' '}
                    {wait > 0 ? (
                        <span>Renvoyer dans {wait} s</span>
                    ) : (
                        <button type="button" onClick={resendCode} disabled={resend.processing} style={{ border: 0, background: 'transparent', padding: 0, cursor: 'pointer', font: 'inherit', fontWeight: 'var(--fw-semibold)', color: 'var(--c-primary-600)' }}>
                            Renvoyer le code
                        </button>
                    )}
                </span>
            </div>
        </form>
    );
}

function ForgotForm() {
    const form = useForm({ email: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/mot-de-passe-oublie');
    };

    return (
        <form onSubmit={submit} noValidate>
            <CardHeader icon="key" title="Mot de passe oublié">
                Indiquez votre adresse e-mail professionnelle. Nous vous enverrons un lien de réinitialisation valable 30 minutes.
            </CardHeader>
            <ErrorAlert message={form.errors.email} />

            <label htmlFor="email" style={labelStyle}>
                Adresse e-mail
            </label>
            <div style={{ ...fieldStyle(!!form.errors.email), marginBottom: 'var(--space-6)' }}>
                <Icon name="mail" color="var(--c-muted)" outlined />
                <input id="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} type="email" placeholder="nom@entreprise.com" autoFocus style={inputStyle} />
            </div>

            <Button type="submit" label="Envoyer le lien" iconRight="send" variant="primary" size="lg" full disabled={form.processing} />
            <div style={{ textAlign: 'center', marginTop: 'var(--space-6)' }}>
                <Link href="/connexion" style={{ fontWeight: 'var(--fw-semibold)' }}>
                    ← Retour à la connexion
                </Link>
            </div>
        </form>
    );
}

function SentNotice({ email }: { email: string }) {
    return (
        <div style={{ textAlign: 'center' }}>
            <CardHeader icon="mark_email_read" iconTone="success" title="Vérifiez votre boîte mail">
                Si un compte existe pour <b style={{ color: 'var(--c-ink)' }}>{email}</b>, un lien de réinitialisation vient d’être envoyé.
            </CardHeader>
            <Button label="Retour à la connexion" icon="arrow_back" variant="secondary" size="lg" full onClick={() => router.visit('/connexion')} />
        </div>
    );
}

function PasswordField({ id, label, value, onChange, invalid, autoComplete, autoFocus }: { id: string; label: string; value: string; onChange: (value: string) => void; invalid: boolean; autoComplete: string; autoFocus?: boolean }) {
    const [visible, setVisible] = useState(false);

    return (
        <>
            <label htmlFor={id} style={{ ...labelStyle, marginTop: 'var(--space-5)' }}>
                {label}
            </label>
            <div style={fieldStyle(invalid, 'var(--space-2)')}>
                <Icon name="lock" color="var(--c-muted)" outlined />
                <input id={id} value={value} onChange={(e) => onChange(e.target.value)} type={visible ? 'text' : 'password'} autoComplete={autoComplete} autoFocus={autoFocus} style={inputStyle} />
                <button
                    type="button"
                    onClick={() => setVisible(!visible)}
                    aria-label={visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
                    className="och-hover-menu"
                    style={{ width: 'var(--control-sm)', height: 'var(--control-sm)', border: 0, background: 'transparent', borderRadius: 'var(--radius-md)', display: 'grid', placeItems: 'center', cursor: 'pointer' }}
                >
                    <Icon name={visible ? 'visibility_off' : 'visibility'} color="var(--c-ink-2)" outlined />
                </button>
            </div>
        </>
    );
}

function PasswordHint() {
    return <p style={{ margin: 'var(--space-2) 0 var(--space-6)', fontSize: 'var(--fs-sm)', color: 'var(--c-muted)' }}>{PASSWORD_HINT}</p>;
}

function ResetForm({ token, email }: { token: string; email: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/reinitialiser-mot-de-passe', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <form onSubmit={submit} noValidate>
            <CardHeader icon="lock_reset" title="Nouveau mot de passe">
                Choisissez un nouveau mot de passe pour <b style={{ color: 'var(--c-ink)' }}>{email}</b>. Vos sessions ouvertes seront fermées.
            </CardHeader>
            <ErrorAlert message={form.errors.token ?? form.errors.email ?? form.errors.password ?? form.errors.password_confirmation} />

            <PasswordField id="password" label="Nouveau mot de passe" value={form.data.password} onChange={(v) => form.setData('password', v)} invalid={!!form.errors.password} autoComplete="new-password" autoFocus />
            <PasswordField id="password_confirmation" label="Confirmez le mot de passe" value={form.data.password_confirmation} onChange={(v) => form.setData('password_confirmation', v)} invalid={!!form.errors.password} autoComplete="new-password" />
            <PasswordHint />

            <Button type="submit" label="Enregistrer le mot de passe" iconRight="arrow_forward" variant="primary" size="lg" full disabled={form.processing} />
            {form.errors.token && (
                <div style={{ textAlign: 'center', marginTop: 'var(--space-6)' }}>
                    <Link href="/mot-de-passe-oublie" style={{ fontWeight: 'var(--fw-semibold)' }}>
                        Demander un nouveau lien
                    </Link>
                </div>
            )}
        </form>
    );
}

function ChangeForm() {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/mot-de-passe', { onFinish: () => form.reset() });
    };

    return (
        <form onSubmit={submit} noValidate>
            <CardHeader icon="lock_reset" title="Nouveau mot de passe">
                Votre mot de passe est provisoire. Choisissez-en un nouveau pour accéder à votre espace.
            </CardHeader>
            <ErrorAlert message={form.errors.current_password ?? form.errors.password ?? form.errors.password_confirmation} />

            <PasswordField id="current_password" label="Mot de passe actuel" value={form.data.current_password} onChange={(v) => form.setData('current_password', v)} invalid={!!form.errors.current_password} autoComplete="current-password" autoFocus />
            <PasswordField id="password" label="Nouveau mot de passe" value={form.data.password} onChange={(v) => form.setData('password', v)} invalid={!!form.errors.password} autoComplete="new-password" />
            <PasswordField id="password_confirmation" label="Confirmez le mot de passe" value={form.data.password_confirmation} onChange={(v) => form.setData('password_confirmation', v)} invalid={!!form.errors.password} autoComplete="new-password" />
            <PasswordHint />

            <Button type="submit" label="Enregistrer et continuer" iconRight="arrow_forward" variant="primary" size="lg" full disabled={form.processing} />
            <div style={{ textAlign: 'center', marginTop: 'var(--space-6)' }}>
                <Link href="/deconnexion" method="post" as="button" style={{ border: 0, background: 'transparent', cursor: 'pointer', font: 'inherit', fontWeight: 'var(--fw-semibold)', color: 'var(--c-primary-600)' }}>
                    Se déconnecter
                </Link>
            </div>
        </form>
    );
}
