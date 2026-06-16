<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Ranetrace — Diagnostics</title>
    <link rel="stylesheet" href="{{ route('ranetrace.assets.css', ['v' => $assetVersion]) }}">
</head>
<body>
    <main class="rt-container" id="rt-app" data-refresh="{{ $refresh }}" data-panels-url="{{ route('ranetrace.dashboard.panels') }}">
        <div id="rt-panels">
            @include('ranetrace::dashboard.panels')
        </div>
    </main>
    <script src="{{ route('ranetrace.assets.js', ['v' => $assetVersion]) }}"></script>
</body>
</html>
