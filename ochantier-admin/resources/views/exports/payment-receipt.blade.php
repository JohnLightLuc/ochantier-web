<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Reçu {{ $payment['ref'] }}</title>
</head>
<body style="font-family:sans-serif;padding:32px;max-width:640px">
    <h1>O’CHANTIER — Reçu de paiement</h1>
    <p><b>{{ $payment['ref'] }}</b> · {{ $payment['date'] }}</p>
    <p>
        Payeur : {{ $promoter->name }}<br>
        Bénéficiaire : {{ $payment['beneficiary'] }}<br>
        Chantier : {{ $payment['site'] }}<br>
        Type : {{ $payment['type'] }} · {{ $payment['method'] }}
    </p>
    <table style="width:100%;border-collapse:collapse">
        <tr><td>Montant brut</td><td align="right">{{ $money($payment['amount']) }}</td></tr>
        <tr><td>Commission O’CHANTIER</td><td align="right">{{ $split['commission'] ? '− '.$money($split['commission']) : '—' }}</td></tr>
        <tr><td>Frais opérateur</td><td align="right">{{ $split['fee'] ? '− '.$money($split['fee']) : '—' }}</td></tr>
        <tr><td><b>Net versé</b></td><td align="right"><b>{{ $money($split['net']) }}</b></td></tr>
    </table>
    <p>Statut : {{ $payment['status'] }}</p>
</body>
</html>
