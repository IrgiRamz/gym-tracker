@extends('layouts.app')
@section('title', 'Alat Gym - Gym Tracker by Neil')

@section('content')
<div class="page-title">Alat Gym 🏋️‍♀️</div>
<div class="page-sub">Daftar alat yang tersedia. AI hanya akan membuat gerakan dari alat yang aktif di sini.</div>

<form method="POST" action="{{ route('alat.store') }}" class="soft-card mb-4">
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
    <button class="btn btn-pink w-100"><i class="bi bi-plus-lg"></i> Tambah Alat</button>
</form>

@forelse($categories as $cat)
    @if(isset($grouped[$cat]))
        <h6 class="fw-bold text-uppercase small mt-3" style="color:var(--muted)">{{ $cat }}</h6>
        @foreach($grouped[$cat] as $eq)
            <div class="agenda-item">
                <div class="agenda-body">
                    <div class="nm {{ $eq->is_available ? '' : 'text-decoration-line-through text-secondary' }}" data-eq-name>{{ $eq->name }}</div>
                    <div class="pg" data-eq-status>{{ $eq->is_available ? 'Tersedia' : 'Sedang tidak tersedia' }}</div>
                </div>
                <button type="button" class="btn btn-soft btn-sm" title="Ubah ketersediaan" data-toggle-btn data-url="{{ route('alat.toggle', $eq) }}"><i class="bi {{ $eq->is_available ? 'bi-toggle-on' : 'bi-toggle-off' }}" data-eq-icon></i></button>
                <form method="POST" action="{{ route('alat.destroy', $eq) }}" data-confirm="{{ $eq->name }} akan dihapus dari daftar alat." data-confirm-title="Hapus alat ini?">@csrf @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger rounded-3"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        @endforeach
    @endif
@empty
@endforelse

@if($grouped->isEmpty())
    <div class="text-center text-secondary py-4">Belum ada alat. Tambahkan alat pertama kamu di atas ya 💕</div>
@endif

@if($grouped->isNotEmpty())
    <form method="POST" action="{{ route('alat.reset') }}" class="mt-4" data-confirm="Semua alat gym akan dihapus permanen dan daftarnya jadi kosong lagi dari 0. Kamu harus input ulang satu per satu, dan AI hanya akan memakai gerakan tanpa alat sampai ada alat baru. Yakin mau reset?" data-confirm-title="Reset semua alat gym?" data-confirm-yes="Ya, Reset Semua">@csrf @method('DELETE')
        <button class="btn btn-outline-danger w-100 rounded-3"><i class="bi bi-arrow-counterclockwise"></i> Reset Semua Alat</button>
    </form>
    <div class="text-center text-secondary small mt-2 mb-3">Tombol ini menghapus seluruh daftar alat, misalnya kalau mau input ulang dari awal.</div>
@endif
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
})();
</script>
@endpush
