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
                    <div class="nm {{ $eq->is_available ? '' : 'text-decoration-line-through text-secondary' }}">{{ $eq->name }}</div>
                    <div class="pg">{{ $eq->is_available ? 'Tersedia' : 'Sedang tidak tersedia' }}</div>
                </div>
                <form method="POST" action="{{ route('alat.toggle', $eq) }}">@csrf @method('PATCH')
                    <button class="btn btn-soft btn-sm" title="Ubah ketersediaan"><i class="bi {{ $eq->is_available ? 'bi-toggle-on' : 'bi-toggle-off' }}"></i></button>
                </form>
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
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-chip-group]').forEach(g => {
    g.addEventListener('click', e => {
        const c = e.target.closest('.chip'); if (!c) return;
        g.querySelectorAll('.chip').forEach(x => x.classList.remove('active'));
        c.classList.add('active');
        g.querySelector('input[type=hidden]').value = c.dataset.value;
    });
});
</script>
@endpush
