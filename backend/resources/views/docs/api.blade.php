<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Dokumentasi API Publik - PPTI VMES</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        .bar { background: #004B23; color: #fff; padding: 12px 20px; font-size: 14px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
        .bar strong { font-size: 16px; }
        .bar a { color: #c7f2a4; }
    </style>
</head>
<body>
    <div class="bar">
        <strong>PPTI VMES &mdash; API Publik v1</strong>
        <span>Spesifikasi: <a href="{{ $spec }}">{{ $spec }}</a></span>
        <span>Butuh API key? Hubungi admin PPTI.</span>
    </div>
    <div id="redoc"></div>
    <script src="https://cdn.jsdelivr.net/npm/redoc@2.1.5/bundles/redoc.standalone.js"></script>
    <script>
        Redoc.init(@json($spec), {
            hideDownloadButton: false,
            expandResponses: '200,201',
            theme: { colors: { primary: { main: '#004B23' } }, typography: { fontFamily: 'system-ui, sans-serif', headings: { fontFamily: 'system-ui, sans-serif' } } },
        }, document.getElementById('redoc'));
    </script>
</body>
</html>
