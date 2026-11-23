@extends('layouts.app')
@section('title', 'Alat Gym - Gym Tracker by Neil')

@section('content')
<div class="page-title">Alat Gym 🏋️‍♀️</div>
<div class="page-sub">Daftar alat yang tersedia. AI hanya akan membuat gerakan dari alat yang aktif di sini.</div>

<form method="POST" action="{{ route('alat.store') }}" class="soft-card mb-4" enctype="multipart/form-data">
    @csrf
    <label class="form-label">Tambah alat baru</label>
    <input type="text" name="name" class="form-control mb-3" placeholder="Contoh: Kettlebell (4 - 12 kg)" value="{{ old('name') }}" required>
    <label class="form-label">Kategori</label>
    <div class="chips mb-3" data-chip-group="category">
        @foreach($categories as $i => $cat)
            <span class="chip {{ old('category', $categories[0]) === $cat ? 'active' : '' }}" data-value="{{ $cat }}">{{ $cat }}</span>
        @endforeach
        <input type="hidden" name="category" value="{{ old('category', $categories[0]) }}">
    </div>
    <label class="form-label">Foto alat <small class="text-secondary fw-normal">opsional, biar gampang mengenali bentuk alatnya</small></label>
    <input type="file" name="image" accept="image/*" class="form-control mb-3">
    <button class="btn btn-pink w-100"><i class="bi bi-plus-lg"></i> Tambah Alat</button>
</form>

@if($grouped->isNotEmpty())
    <div class="input-group mb-2">
        <span class="input-group-text" style="background:#fff"><i class="bi bi-search"></i></span>
        <input type="text" id="eqSearch" class="form-control" placeholder="Cari alat… misalnya: dumbbell" autocomplete="off">
    </div>
    <div id="eqSearchEmpty" class="text-center text-secondary py-4 d-none">Tidak ada alat yang cocok dengan pencarian "<b id="eqSearchTerm"></b>". Coba kata kunci lain ya.</div>
@endif

@forelse($categories as $cat)
    @if(isset($grouped[$cat]))
        <div data-eq-group data-category="{{ $cat }}">
        <h6 class="fw-bold text-uppercase small mt-3" style="color:var(--muted)">{{ $cat }}</h6>
        @foreach($grouped[$cat] as $eq)
            <div class="agenda-item">
                @if($eq->image_path)
                    <img src="{{ $eq->imageUrl() }}" alt="Foto {{ $eq->name }}" class="rounded-3 flex-shrink-0" style="width:52px;height:52px;object-fit:cover;cursor:pointer" title="Lihat foto alat" data-eq-photo-view data-name="{{ $eq->name }}" data-full="{{ $eq->imageUrl() }}">
                @endif
                <div class="agenda-body">
                    <div class="nm {{ $eq->is_available ? '' : 'text-decoration-line-through text-secondary' }}" data-eq-name>{{ $eq->name }}</div>
                    <div class="pg" data-eq-status>{{ $eq->is_available ? 'Tersedia' : 'Sedang tidak tersedia' }}</div>
                    @if($eq->image_path)
                        <form method="POST" action="{{ route('alat.foto.hapus', $eq) }}" data-confirm="Foto alat ini akan dihapus. Alatnya tetap ada di daftar." data-confirm-title="Hapus foto alat?">@csrf @method('DELETE')
                            <button class="btn btn-link btn-sm p-0 text-danger text-decoration-none" style="font-size:.72rem">Hapus foto</button>
                        </form>
                    @endif
                </div>
                <button type="button" class="btn btn-soft btn-sm" title="Ubah ketersediaan" data-toggle-btn data-url="{{ route('alat.toggle', $eq) }}"><i class="bi {{ $eq->is_available ? 'bi-toggle-on' : 'bi-toggle-off' }}" data-eq-icon></i></button>
                <form method="POST" action="{{ route('alat.foto', $eq) }}" enctype="multipart/form-data">@csrf @method('PATCH')
                    <label class="btn btn-soft btn-sm mb-0" style="cursor:pointer" title="{{ $eq->image_path ? 'Ganti foto alat' : 'Tambah foto alat' }}"><i class="bi bi-camera"></i><input type="file" name="image" accept="image/*" class="d-none" data-photo-input></label>
                </form>
                <form method="POST" action="{{ route('alat.destroy', $eq) }}" data-confirm="{{ $eq->name }} akan dihapus dari daftar alat." data-confirm-title="Hapus alat ini?">@csrf @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger rounded-3"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        @endforeach
        </div>
    @endif
@empty
@endforelse

@if($grouped->isEmpty())
    <div class="text-center text-secondary py-4">Belum ada alat. Tambahkan alat pertama kamu di atas ya 💕</div>
@endif

@if($grouped->isNotEmpty())
    <form method="POST" action="{{ route('alat.reset') }}" class="mt-4" data-confirm="Semua alat gym akan dihapus permanen dan daftarnya jadi kosong lagi dari 0. Kamu harus input ulang satu per satu, dan program tidak bisa dibuat sampai ada alat baru karena semua gerakan wajib memakai alat. Yakin mau reset?" data-confirm-title="Reset semua alat gym?" data-confirm-yes="Ya, Reset Semua">@csrf @method('DELETE')
        <button class="btn btn-outline-danger w-100 rounded-3"><i class="bi bi-arrow-counterclockwise"></i> Reset Semua Alat</button>
    </form>
    <div class="text-center text-secondary small mt-2 mb-3">Tombol ini menghapus seluruh daftar alat, misalnya kalau mau input ulang dari awal.</div>
@endif

<div class="soft-card mt-4">
    <label class="form-label">Media Gerakan 🎬</label>
    <div class="small text-secondary mb-3">Contoh gerakan berupa video pendek yang muter, GIF, atau gambar. Muncul sebagai tombol putar di <b>Lihat Program</b> dan di <b>sesi live</b>, di samping tombol foto alat. Gerakan yang belum ada medianya tidak menampilkan tombol apa pun. Sekali isi, medianya berlaku untuk semua klien yang gerakannya sama.</div>
    <form method="POST" action="{{ route('gerakan.store') }}" enctype="multipart/form-data">
        @csrf
        <input type="text" name="name" class="form-control mb-2" placeholder="Nama gerakan, contoh: Pec Deck" required>
        <input type="file" name="media" accept="video/mp4,video/webm,video/quicktime,image/*" class="form-control mb-3" required>
        <button class="btn btn-pink w-100"><i class="bi bi-plus-lg"></i> Tambah Media Gerakan</button>
    </form>
</div>

@if(!empty($exerciseRows))
    <div class="input-group mb-2 mt-4">
        <span class="input-group-text" style="background:#fff"><i class="bi bi-search"></i></span>
        <input type="text" id="mvSearch" class="form-control" placeholder="Cari gerakan… misalnya: squat" autocomplete="off">
    </div>
    <div id="mvSearchEmpty" class="text-center text-secondary py-4 d-none">Tidak ada gerakan yang cocok dengan pencarian "<b id="mvSearchTerm"></b>". Coba kata kunci lain ya.</div>
    <div data-mv-group>
    <h6 class="fw-bold text-uppercase small mt-3" style="color:var(--muted)">Daftar Gerakan</h6>
    @foreach($exerciseRows as $row)
        <div class="agenda-item">
            @if($row['media']?->media_path)
                @if($row['media']->kind() === 'video')
                    <video src="{{ $row['media']->mediaUrl() }}" class="rounded-3 flex-shrink-0" style="width:52px;height:52px;object-fit:cover;cursor:pointer" muted loop playsinline preload="metadata" title="Lihat contoh gerakan" data-mv-view data-name="{{ $row['name'] }}" data-url="{{ $row['media']->mediaUrl() }}" data-kind="video" data-credit="{{ $row['media']->credit }}"></video>
                @else
                    <img src="{{ $row['media']->mediaUrl() }}" alt="Contoh {{ $row['name'] }}" class="rounded-3 flex-shrink-0" style="width:52px;height:52px;object-fit:cover;cursor:pointer" title="Lihat contoh gerakan" data-mv-view data-name="{{ $row['name'] }}" data-url="{{ $row['media']->mediaUrl() }}" data-kind="image" data-credit="{{ $row['media']->credit }}">
                @endif
            @endif
            <div class="agenda-body">
                <div class="nm" data-mv-name>{{ $row['name'] }}</div>
                <div class="pg">{{ $row['media']?->media_path ? 'Sudah ada contoh gerakan' : 'Belum ada contoh gerakan' }}</div>
                @if($row['media'])
                    <form method="POST" action="{{ route('gerakan.destroy', $row['media']) }}" data-confirm="Media gerakan ini akan dihapus. Gerakannya tetap ada di program klien." data-confirm-title="Hapus media gerakan?">@csrf @method('DELETE')
                        <button class="btn btn-link btn-sm p-0 text-danger text-decoration-none" style="font-size:.72rem">Hapus media</button>
                    </form>
                @endif
            </div>
            <form method="POST" action="{{ route('gerakan.store') }}" enctype="multipart/form-data">@csrf
                <input type="hidden" name="name" value="{{ $row['name'] }}">
                <label class="btn btn-soft btn-sm mb-0" style="cursor:pointer" title="{{ $row['media']?->media_path ? 'Ganti media gerakan' : 'Tambah media gerakan' }}"><i class="bi bi-camera-reels"></i><input type="file" name="media" accept="video/mp4,video/webm,video/quicktime,image/*" class="d-none" data-photo-input></label>
            </form>
        </div>
    @endforeach
    </div>
@endif

{{-- Lihat foto alat ukuran penuh --}}
<div class="modal fade" id="equipmentImageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:540px">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title fw-bold" id="equipmentImageTitle">Foto Alat</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body text-center"><img id="equipmentImageImg" class="img-fluid rounded-4" alt="Foto alat gym"></div>
        </div>
    </div>
</div>

{{-- Lihat contoh gerakan ukuran penuh --}}
<div class="modal fade" id="exerciseMediaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:540px">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title fw-bold" id="exerciseMediaTitle">Contoh Gerakan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body text-center">
                <video id="exerciseMediaVideo" class="img-fluid rounded-4 d-none" autoplay muted loop playsinline controls></video>
                <img id="exerciseMediaImg" class="img-fluid rounded-4 d-none" alt="Contoh gerakan">
                <div id="exerciseMediaCredit" class="small text-secondary mt-2 d-none"></div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    document.querySelectorAll('[data-chip-group]').forEach(g => {
        g.addEventListener('click', e => {
            const c = e.target.closest('.chip'); if (!c) return;
            g.querySelectorAll('.chip').forEach(x => x.classList.remove('active'));
            c.classList.add('active');
            g.querySelector('input[type=hidden]').value = c.dataset.value;
        });
    });

    // Pilih foto alat: langsung unggah begitu file dipilih.
    document.querySelectorAll('[data-photo-input]').forEach(inp => {
        inp.addEventListener('change', () => { if (inp.files.length) inp.closest('form').submit(); });
    });

    // Klik thumbnail foto alat: lihat ukuran penuh di modal.
    document.querySelectorAll('[data-eq-photo-view]').forEach(img => {
        img.addEventListener('click', () => {
            document.getElementById('equipmentImageTitle').textContent = 'Foto ' + img.dataset.name;
            document.getElementById('equipmentImageImg').src = img.dataset.full;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('equipmentImageModal')).show();
        });
    });

    // Klik thumbnail media gerakan: lihat ukuran penuh di modal (video diputar, gambar ditampilkan).
    document.querySelectorAll('[data-mv-view]').forEach(el => {
        el.addEventListener('click', () => {
            document.getElementById('exerciseMediaTitle').textContent = 'Gerakan ' + el.dataset.name;
            const cr = document.getElementById('exerciseMediaCredit');
            if (el.dataset.credit) { cr.textContent = el.dataset.credit; cr.classList.remove('d-none'); } else { cr.textContent = ''; cr.classList.add('d-none'); }
            const vid = document.getElementById('exerciseMediaVideo'), img = document.getElementById('exerciseMediaImg');
            if (el.dataset.kind === 'video') { img.classList.add('d-none'); img.removeAttribute('src'); vid.classList.remove('d-none'); vid.src = el.dataset.url; vid.play().catch(() => {}); }
            else { vid.classList.add('d-none'); vid.pause(); vid.removeAttribute('src'); img.classList.remove('d-none'); img.src = el.dataset.url; }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('exerciseMediaModal')).show();
        });
    });

    // Modal contoh gerakan ditutup: hentikan video agar tidak tetap berputar di latar.
    document.getElementById('exerciseMediaModal').addEventListener('hidden.bs.modal', () => {
        const vid = document.getElementById('exerciseMediaVideo');
        vid.pause(); vid.removeAttribute('src'); vid.load();
        document.getElementById('exerciseMediaImg').removeAttribute('src');
    });

    // Toggle ketersediaan alat via AJAX: halaman tidak reload, scroll tetap di tempat.
    document.querySelectorAll('[data-toggle-btn]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const item = btn.closest('.agenda-item');
            const nameEl = item.querySelector('[data-eq-name]');
            const statusEl = item.querySelector('[data-eq-status]');
            const iconEl = btn.querySelector('[data-eq-icon]');
            btn.disabled = true;
            try {
                const data = await api(btn.dataset.url, 'PATCH');
                const on = !!data.is_available;
                nameEl.classList.toggle('text-decoration-line-through', !on);
                nameEl.classList.toggle('text-secondary', !on);
                statusEl.textContent = on ? 'Tersedia' : 'Sedang tidak tersedia';
                iconEl.className = 'bi ' + (on ? 'bi-toggle-on' : 'bi-toggle-off');
                if (data.message) toast(data.message);
            } catch (err) {
                toast(err.message || 'Terjadi kendala, coba lagi ya.', 'danger');
            } finally {
                btn.disabled = false;
            }
        });
    });

    // Cari alat: saring daftar langsung saat mengetik, tanpa reload.
    // Cocokkan nama alat ATAU nama kategorinya; grup kategori yang kosong ikut disembunyikan.
    const eqSearch = document.getElementById('eqSearch');
    if (eqSearch) eqSearch.addEventListener('input', () => {
        const q = eqSearch.value.trim().toLowerCase();
        let visible = 0;
        document.querySelectorAll('[data-eq-group]').forEach(g => {
            const catMatch = !q || (g.dataset.category || '').toLowerCase().includes(q);
            let groupVisible = 0;
            g.querySelectorAll('.agenda-item').forEach(row => {
                const nameEl = row.querySelector('[data-eq-name]');
                const show = catMatch || !q || (nameEl ? nameEl.textContent.toLowerCase().includes(q) : false);
                row.style.display = show ? '' : 'none';
                if (show) groupVisible++;
            });
            g.style.display = groupVisible ? '' : 'none';
            visible += groupVisible;
        });
        document.getElementById('eqSearchTerm').textContent = eqSearch.value.trim();
        document.getElementById('eqSearchEmpty').classList.toggle('d-none', visible > 0);
    });

    // Cari gerakan: saring daftar gerakan di bagian Media Gerakan, langsung saat mengetik.
    const mvSearch = document.getElementById('mvSearch');
    if (mvSearch) mvSearch.addEventListener('input', () => {
        const q = mvSearch.value.trim().toLowerCase();
        let visible = 0;
        document.querySelectorAll('[data-mv-group] .agenda-item').forEach(row => {
            const nameEl = row.querySelector('[data-mv-name]');
            const show = !q || (nameEl ? nameEl.textContent.toLowerCase().includes(q) : false);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        document.getElementById('mvSearchTerm').textContent = mvSearch.value.trim();
        document.getElementById('mvSearchEmpty').classList.toggle('d-none', visible > 0);
    });
})();
</script>
@endpush
