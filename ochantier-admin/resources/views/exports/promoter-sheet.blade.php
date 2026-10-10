<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Fiche {{ $promoter->name }}</title>
</head>
<body style="font-family:sans-serif;padding:32px">
    <h1>{{ $promoter->name }}</h1>
    <p>{{ $promoter->representative }} · {{ $promoter->representative_role }}</p>
    <table>
        @foreach ($rows as $label => $value)
            <tr>
                <td style="padding:6px 12px;color:#555">{{ $label }}</td>
                <td style="padding:6px 12px"><b>{{ $value ?: '—' }}</b></td>
            </tr>
        @endforeach
    </table>
    <p style="color:#888">Exporté depuis O’CHANTIER Admin le {{ now()->translatedFormat('j M Y') }}</p>
</body>
</html>
