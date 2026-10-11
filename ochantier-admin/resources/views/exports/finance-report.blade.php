<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $name }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #1b1f24; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        table { border-collapse: collapse; width: 100%; margin-top: 12px; }
        td, th { border: 1px solid #ccd2da; padding: 4px 6px; text-align: left; }
        th { background: #eef1f5; }
        .meta td { border: 0; padding: 2px 6px; }
    </style>
</head>
<body>
    <h1>{{ $name }}</h1>
    <table class="meta">
        @foreach ($meta as [$label, $value])
            <tr><td><b>{{ $label }}</b></td><td>{{ $value }}</td></tr>
        @endforeach
    </table>
    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $value)
                        <td>{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($print)
        <script>window.onload = function () { window.print(); };</script>
    @endif
</body>
</html>
