<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GitoBob · Repozitáře</title>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
</head>
<body>
    <header><strong>GitoBob</strong><span>Lokální repozitáře</span></header>
    <main>
        <section class="heading">
            <div><h1>Pracovní složka</h1>
                @if ($path)<p class="path">{{ $path }}</p>@endif
            </div>
            <form method="post" action="{{ route('workspace.choose') }}">
                @csrf
                <button>{{ $path ? 'Změnit složku' : 'Vybrat složku' }}</button>
            </form>
        </section>
        @if ($tree)
            <section class="panel" aria-label="Strom repozitářů">
                <div class="toolbar"><h2>Repozitáře</h2><a href="{{ route('workspace') }}">Obnovit</a></div>
                <ul class="tree">@include('folder', ['folder' => $tree])</ul>
            </section>
        @elseif (! $path)
            <section class="empty"><h2>Vyber pracovní složku</h2><p>GitoBob v ní najde Git repozitáře, včetně těch ve vnořených složkách.</p></section>
        @elseif (! $available)
            <section class="empty"><h2>Složka není dostupná</h2><p>Zkontroluj její umístění a oprávnění, nebo vyber jinou složku.</p><a href="{{ route('workspace') }}">Zkusit znovu</a></section>
        @else
            <section class="empty"><h2>Žádné repozitáře</h2><p>Ve vybrané složce jsme nenašli žádný Git repozitář.</p><a href="{{ route('workspace') }}">Obnovit</a></section>
        @endif
    </main>
</body>
</html>
