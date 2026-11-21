<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#ec4899">
    <meta name="turbo-cache-control" content="no-preview">
    <title>@yield('title', 'Gym Tracker by Neil')</title>

    {{-- PWA --}}
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="{{ asset('images/icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Gym Tracker">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}" rel="stylesheet">

    {{-- Script global: dimuat sekali di head (urutan penting: Bootstrap -> app.js -> Turbo) --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
    <script src="https://cdn.jsdelivr.net/npm/@hotwired/turbo@8.0.4/dist/turbo.es2017-umd.js"></script>
    @stack('head')
</head>
<body>
<div class="app-shell">
    {{-- Header permanen: tidak dirender ulang saat pindah menu, badge WA tetap tampil konsisten --}}
    <header class="app-header" id="appHeader" data-turbo-permanent>
        <div class="brand">
            <div class="brand-icon"><i class="bi bi-heart-pulse-fill"></i></div>
            <div>Gym Tracker<small>by Neil</small></div>
        </div>
        @php
            // $waState: true = aktif, false = tidak aktif, null = belum dicek (dicek via AJAX di browser)
            $waClass = $waState === true ? 'bg-success-subtle text-success' : ($waState === false ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary');
            $waLabel = $waState === true ? 'WA Aktif' : ($waState === false ? 'WA Tidak Aktif' : 'Cek WA...');
        @endphp
        <span id="waBadge" class="wa-badge {{ $waClass }}" data-url="{{ route('wa.status') }}"
              data-state="{{ $waState === true ? 'on' : ($waState === false ? 'off' : 'checking') }}">
            <span class="dot"></span><span id="waText">{{ $waLabel }}</span>
        </span>
    </header>

    <div class="toast-wrap">
        @if(session('success'))
            <div class="alert alert-success shadow-sm rounded-4 alert-dismissible fade show auto-dismiss">{{ session('success') }}<button class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger shadow-sm rounded-4 alert-dismissible fade show">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                <button class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        <div id="toastSlot"></div>
    </div>

    <main class="page">@yield('content')</main>

    {{-- Bottom nav permanen: indikator aktif berpindah halus (diatur app.js) --}}
    <nav class="bottom-nav" id="bottomNav" data-turbo-permanent>
        <a href="{{ route('kalender') }}" data-nav="kalender" class="{{ request()->routeIs('kalender') ? 'active' : '' }}"><i class="bi bi-calendar-heart"></i>Kalender</a>
        <a href="{{ route('klien.index') }}" data-nav="klien" class="{{ request()->routeIs('klien.*') ? 'active' : '' }}"><i class="bi bi-people-fill"></i>Klien</a>
        <a href="{{ route('alat.index') }}" data-nav="alat" class="{{ request()->routeIs('alat.*') ? 'active' : '' }}"><i class="bi bi-trophy-fill"></i>Alat Gym</a>
    </nav>
</div>

{{-- Modal konfirmasi (pengganti confirm() bawaan browser) --}}
<div class="modal fade" id="confirmModal" tabindex="-1" style="z-index:1100">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
        <div class="modal-content text-center p-3">
            <div class="modal-body">
                <div id="confirmIcon" class="mb-2" style="font-size:2.4rem">💭</div>
                <h5 class="fw-bold mb-2" id="confirmTitle">Yakin?</h5>
                <div class="text-secondary" id="confirmText"></div>
            </div>
            <div class="d-flex gap-2 px-3 pb-2">
                <button type="button" class="btn btn-soft flex-fill py-2" id="confirmNo">Kembali</button>
                <button type="button" class="btn btn-pink flex-fill py-2" id="confirmYes">Ya</button>
            </div>
        </div>
    </div>
</div>

@stack('scripts')
</body>
</html>
