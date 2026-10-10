/** Montant entier au format français (espaces fines insécables remplacées par des espaces). */
export function formatNumber(value: number): string {
    return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(value).replace(/ | /g, ' ');
}

/** Millions de FCFA → montant complet arrondi au millier. */
export function formatMillions(millions: number): string {
    return formatNumber(Math.round(millions * 1000) * 1000);
}

/** Libellé court d’axe : 2,5 M. */
export function formatAxisMillions(millions: number): string {
    if (!millions) return '0';
    return String(Math.round(millions * 10) / 10).replace('.', ',') + ' M';
}

/** Pas « rond » supérieur ou égal à v (1, 2, 2,5, 5, 10 × 10^n). */
export function niceStep(v: number): number {
    const p = Math.pow(10, Math.floor(Math.log10(v)));
    for (const m of [1, 2, 2.5, 5, 10]) if (m * p >= v) return m * p;
    return 10 * p;
}

export function currentTime(): string {
    const d = new Date();
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
}

/** Courbe lissée (Catmull-Rom → Bézier) dans un repère 0–100. */
export function smoothPath(points: [number, number][]): string {
    let d = `M${points[0][0]},${points[0][1]}`;
    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i - 1] ?? points[i];
        const p1 = points[i];
        const p2 = points[i + 1];
        const p3 = points[i + 2] ?? p2;
        d += ` C${p1[0] + (p2[0] - p0[0]) / 6},${p1[1] + (p2[1] - p0[1]) / 6} ${p2[0] - (p3[0] - p1[0]) / 6},${p2[1] - (p3[1] - p1[1]) / 6} ${p2[0]},${p2[1]}`;
    }
    return d;
}
