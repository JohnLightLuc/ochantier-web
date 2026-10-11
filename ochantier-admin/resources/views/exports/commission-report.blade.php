@php($money = fn (int $v): string => number_format($v, 0, ',', ' '))
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport de commissions O’CHANTIER</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 860px; margin: 40px auto; color: #1d2433; }
        td, th { padding: 4px 8px; border-bottom: 1px solid #ddd; text-align: left; }
        .num { text-align: right; }
        .muted { color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <h2>Rapport de commissions O’CHANTIER</h2>
    <p>Période : {{ $period }} · généré le {{ $generatedAt }} par {{ $author }} · {{ count($rows) }} opération(s) · règle {{ $rule['version'] }} (Y {{ $rule['y'] }} %, Z {{ $rule['z'] }} %)</p>
    @if ($total)
        <p>Volume brut {{ $money($total['gross']) }} FCFA · calculées {{ $money($total['calc']) }} · validées {{ $money($total['validated']) }} · encaissées {{ $money($total['collected']) }} · remboursées {{ $money($total['refunded']) }} · extournées {{ $money($total['reversed']) }} · <b>nettes {{ $money($total['net']) }} FCFA</b></p>
    @endif
    @if ($format === 'excel')
        <table>
            <tr><th>Référence</th><th>Date</th><th>Promoteur</th><th>Chantier</th><th>Brut</th><th>Commission</th><th>Nette</th><th>Statut</th></tr>
            @foreach ($rows as $row)
                <tr><td>{{ $row['payment']->reference }}</td><td>{{ $row['payment']->date->format('d/m/Y') }}</td><td>{{ $row['payment']->site->promoter_name }}</td><td>{{ $row['payment']->site->name }}</td><td class="num">{{ $row['calc']['gross'] }}</td><td class="num">{{ $row['calc']['com'] }}</td><td class="num">{{ $row['calc']['comNet'] }}</td><td>{{ $row['calc']['comStatus'] }}</td></tr>
            @endforeach
        </table>
    @else
        @foreach (['Par chantier' => $bySite, 'Par promoteur' => $byPromoter] as $title => $groups)
            <h3>{{ $title }}</h3>
            <table style="width:100%;border-collapse:collapse">
                @foreach ($groups as $label => $m)
                    <tr><td>{{ $label }}</td><td class="num">{{ $m['n'] }} op.</td><td class="num">{{ $money($m['net']) }} FCFA</td></tr>
                @endforeach
            </table>
        @endforeach
        <p class="muted">Imprimer en PDF depuis le navigateur.</p>
    @endif
</body>
</html>
