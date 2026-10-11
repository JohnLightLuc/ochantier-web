<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $name }}</title>
    <style>
        body { font: 13px system-ui, sans-serif; color: #1f2a24; margin: 32px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 24px 0 8px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #dde3df; padding: 6px 8px; text-align: left; }
        th { background: #f2f5f3; }
        .meta { color: #5d6b63; margin: 0 0 2px; }
    </style>
</head>
<body>
    <h1>{{ $name }}</h1>
    @foreach ($meta as [$label, $value])
        <p class="meta">{{ $label }} : {{ $value }}</p>
    @endforeach
    @foreach ($blocks as [$title, $columns, $rows])
        <h2>{{ $title }}</h2>
        <table>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $value)
                        <td>{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endforeach
    @if ($print)
        <script>window.onload = function () { window.print(); };</script>
    @endif
</body>
</html>
