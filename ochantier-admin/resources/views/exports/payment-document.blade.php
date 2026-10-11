@php($money = fn (int $v): string => number_format($v, 0, ',', ' ').' FCFA')
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $type }} {{ $payment->reference }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 40px auto; color: #1d2433; }
        td { padding: 6px 10px; border-bottom: 1px solid #ddd; }
        td:last-child { text-align: right; }
        .muted { color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <h2>O’CHANTIER · {{ $type }}</h2>
    <p>
        {{ $payment->reference }} · {{ $payment->type }} · {{ $payment->period_start->format('d/m/Y') }} → {{ $payment->period_end->format('d/m/Y') }}<br>
        Promoteur : {{ $payment->site->promoter_name }} · Chantier : {{ $payment->site->name }}
        @if ($payment->worker)
            <br>Ouvrier : {{ $payment->worker->name }} ({{ $payment->worker->code }})
        @endif
    </p>
    <table style="width:100%;border-collapse:collapse">
        <tr><td>Montant de la prestation</td><td>{{ $money($calc['gross']) }}</td></tr>
        <tr><td>Bonus</td><td>{{ $money($calc['bonus']) }}</td></tr>
        <tr><td>Indemnités</td><td>{{ $money($calc['indemnity']) }}</td></tr>
        <tr><td>Retenues</td><td>−{{ $money($calc['deduction']) }}</td></tr>
        <tr><td>Commission O’CHANTIER (promoteur {{ $calc['y'] }} % / ouvrier {{ $calc['z'] }} %)</td><td>{{ $money($calc['com']) }}</td></tr>
        <tr><td>Frais externes ({{ $payment->method ?? '—' }})</td><td>{{ $money($calc['fees']) }}</td></tr>
        <tr><td>Taxes</td><td>{{ $money($calc['tax']) }}</td></tr>
        <tr><td><b>Total payé par le promoteur</b></td><td><b>{{ $money($calc['promoterTotal']) }}</b></td></tr>
        <tr><td><b>Net ouvrier</b></td><td><b>{{ $money($calc['net']) }}</b></td></tr>
    </table>
    <p class="muted">Règle {{ $payment->rule_version }} · généré le {{ $generatedAt }} par {{ $author }} · aucune donnée bancaire complète.</p>
</body>
</html>
