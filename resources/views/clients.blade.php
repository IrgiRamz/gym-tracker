@extends('layouts.app')
@section('title', 'Klien - Gym Tracker by Neil')

@section('content')
<div class="d-flex justify-content-between align-items-start">
    <div>
        <div class="page-title">Klien Kamu 💕</div>
        <div class="page-sub">{{ $clients->count() }} klien aktif</div>
    </div>
    <button class="btn btn-pink btn-sm" data-bs-toggle="modal" data-bs-target="#wizardModal" id="openWizard"><i class="bi bi-person-plus-fill"></i> Klien Baru</button>
</div>

@if($clients->isNotEmpty())
    <div class="input-group mb-3">
        <span class="input-group-text" style="background:#fff"><i class="bi bi-search"></i></span>
        <input type="text" id="clientSearch" class="form-control" placeholder="Cari nama" autocomplete="off">
    </div>
    <div id="clientSearchEmpty" class="text-center text-secondary py-4 d-none">Tidak ada klien yang cocok dengan pencarian "<b id="clientSearchTerm"></b>". Coba nama lain ya.</div>
@endif

@forelse($clients as $c)
    <div class="client-card" data-client-card data-name="{{ mb_strtolower($c->name) }}">
        <button type="button" class="client-head" data-client-toggle aria-expanded="false">
            <span class="avatar">{{ mb_strtoupper(mb_substr($c->name, 0, 1)) }}</span>
            <span class="flex-grow-1" style="min-width:0">
                <span class="fw-bold d-block text-truncate">{{ $c->name }}</span>
                <span class="small text-secondary d-block"><i class="bi bi-whatsapp"></i> {{ $c->phone }}</span>
            </span>
            <i class="bi bi-chevron-down client-chev"></i>
        </button>
        <div class="client-detail"><div class="client-detail-inner"><div style="padding-top:10px">
            <div class="mb-2">
                <span class="tag">{{ $c->goal }}</span>@foreach($c->body_focus ?? [] as $f)<span class="tag">{{ $f }}</span>@endforeach<span class="tag">{{ $c->pt_frequency_per_week }}x / minggu</span>
                @if($c->age)<span class="tag">Umur {{ $c->age }} th</span>@endif
                @if($c->waist_cm)<span class="tag">Lingkar perut {{ $c->waist_cm }} cm</span>@endif
            </div>
            @if($c->goal_note)<div class="small text-secondary mb-2">🎯 {{ $c->goal_note }}</div>@endif
            <div class="row g-2 mb-3">
                <div class="col-4"><div class="stat"><b>{{ $c->remaining_sessions }}</b><span>Sisa sesi</span></div></div>
                @php
                    $first = $c->weightLogs->first();
                    $diff = $first ? round($c->weight_kg - $first->weight_kg, 1) : 0;
                @endphp
                <div class="col-4"><div class="stat"><b>{{ rtrim(rtrim(number_format($c->weight_kg, 1), '0'), '.') }} kg</b>
                    <span>Berat @if($diff != 0)<b class="d-inline" style="font-size:.68rem;color:{{ $diff < 0 ? '#16a34a' : '#db2777' }}">({{ $diff > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($diff, 1), '0'), '.') }})</b>@endif</span></div></div>
                <div class="col-4"><div class="stat"><b style="font-size:.9rem">{{ $c->daily_protein_min && $c->daily_protein_max ? $c->daily_protein_min . '–' . $c->daily_protein_max : $c->daily_protein_target }} g</b><span>Protein/hari</span></div></div>
            </div>
            @if($c->notes)<div class="small text-secondary mb-2">📝 {{ $c->notes }}</div>@endif
            <div class="d-flex gap-2">
                <button class="btn btn-soft flex-fill btn-sm" data-bs-toggle="modal" data-bs-target="#programModal" data-client="{{ json_encode(['name' => $c->name, 'programs' => $c->activePrograms]) }}"><i class="bi bi-list-check"></i> Lihat Program</button>
                <button class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#historyModal" data-id="{{ $c->id }}" data-name="{{ $c->name }}"><i class="bi bi-clock-history"></i> History</button>
                <button class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#weightModal" data-id="{{ $c->id }}" data-name="{{ $c->name }}" data-weight="{{ $c->weight_kg + 0 }}" data-logs="{{ json_encode($c->weightLogs->map(fn ($l) => ['d' => $l->recorded_at->format('d M Y'), 'w' => $l->weight_kg + 0])->values()) }}"><i class="bi bi-speedometer2"></i> BB</button>
                <button class="btn btn-soft btn-sm" data-bs-toggle="modal" data-bs-target="#sessionModal" data-id="{{ $c->id }}" data-name="{{ $c->name }}"><i class="bi bi-plus-circle"></i> Paket</button>
            </div>
            <div class="d-flex gap-2 mt-2">
                <button type="button" class="btn btn-soft btn-sm flex-fill" title="Edit data klien" data-bs-toggle="modal" data-bs-target="#editModal"
                    data-client="{{ json_encode(['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'gender' => $c->gender, 'age' => $c->age, 'weight_kg' => (float) $c->weight_kg, 'height_cm' => $c->height_cm, 'waist_cm' => $c->waist_cm, 'goal' => $c->goal, 'goal_note' => $c->goal_note, 'body_focus' => $c->body_focus ?? [], 'pt_frequency_per_week' => (int) $c->pt_frequency_per_week, 'activity_level' => $c->activity_level ?? 'Cukup Aktif', 'remaining_sessions' => (int) $c->remaining_sessions, 'notes' => $c->notes]) }}"><i class="bi bi-pencil-fill"></i> Edit Data</button>
                <form method="POST" action="{{ route('klien.destroy', $c) }}" data-confirm="Data {{ $c->name }} beserta seluruh jadwal dan programnya akan dihapus permanen." data-confirm-title="Hapus klien ini?">@csrf @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Hapus</button>
                </form>
            </div>
        </div></div></div>
    </div>
@empty
    <div class="text-center text-secondary py-5">Belum ada klien. Ketuk <b>Klien Baru</b> untuk mulai ya ✨</div>
@endforelse

{{-- Wizard klien baru --}}
<div class="modal fade" id="wizardModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down" style="max-width:540px">
        <form class="modal-content" method="POST" action="{{ route('klien.store') }}" id="wizardForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Klien Baru ✨</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="wizard-steps"><i class="on"></i><i></i><i></i></div>

                <div class="wstep" data-step="1">
                    <div class="mb-3"><label class="form-label">Nama klien</label>
                        <input name="name" class="form-control" placeholder="Nama lengkap" required></div>
                    <div class="mb-3"><label class="form-label">Nomor WhatsApp</label>
                        <input name="phone" class="form-control" inputmode="tel" placeholder="0812xxxxxxxx" required></div>
                    <div class="mb-3"><label class="form-label">Gender</label>
                        <div class="chips" data-chip-group="gender">
                            <span class="chip active" data-value="Wanita">👩 Wanita</span>
                            <span class="chip" data-value="Pria">👨 Pria</span>
                            <input type="hidden" name="gender" value="Wanita">
                        </div></div>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label">Berat (kg)</label>
                            <input name="weight_kg" type="number" step="0.1" min="20" class="form-control" inputmode="decimal" required></div>
                        <div class="col-6"><label class="form-label">Tinggi (cm) <small class="text-secondary fw-normal">opsional</small></label>
                            <input name="height_cm" type="number" class="form-control" inputmode="numeric"></div>
                    </div>
                    <div class="row g-2 mt-3">
                        <div class="col-6"><label class="form-label">Umur (tahun)</label>
                            <input name="age" type="number" min="5" max="100" class="form-control" inputmode="numeric" placeholder="Contoh: 25" required></div>
                        <div class="col-6"><label class="form-label">Lingkar perut (cm) <small class="text-secondary fw-normal">opsional</small></label>
                            <input name="waist_cm" type="number" min="40" max="250" class="form-control" inputmode="numeric" placeholder="Boleh dikosongkan"></div>
                    </div>
                    <div class="form-text mt-2">Lingkar perut cuma diisi bila klien nyaman diukur ya 💗</div>
                </div>

                <div class="wstep d-none" data-step="2">
                    <div class="mb-3"><label class="form-label">Tujuan utama</label>
                        <div class="chips" data-chip-group="goal">
                            @foreach($goals as $i => $g)<span class="chip {{ $i === 0 ? 'active' : '' }}" data-value="{{ $g }}">{{ $g }}</span>@endforeach
                            <input type="hidden" name="goal" value="{{ $goals[0] }}">
                        </div></div>
                    <div class="mb-3"><label class="form-label">Alasan / tujuan khusus <small class="text-secondary fw-normal">opsional, bebas ditulis</small></label>
                        <input name="goal_note" class="form-control" maxlength="200" placeholder="Contoh: mau kuat lari jauh, persiapan marathon bulan Oktober"></div>
                    <div class="mb-3"><label class="form-label">Fokus bagian tubuh <small class="text-secondary fw-normal">bisa pilih lebih dari satu</small></label>
                        <div class="chips" id="focusChips">
                            @foreach($focuses as $f)<span class="chip {{ $f === 'Seluruh Tubuh' ? 'active' : '' }}" data-value="{{ $f }}">{{ $f }}</span>@endforeach
                        </div>
                        <div id="focusInputs"><input type="hidden" name="body_focus[]" value="Seluruh Tubuh"></div>
                        <div class="text-danger small mt-1 d-none" id="focusErr">Pilih minimal satu fokus ya.</div>
                    </div>
                    <div class="mb-3"><label class="form-label">Sesi PT per minggu</label>
                        <div class="chips" data-chip-group="pt_frequency_per_week">
                            <span class="chip" data-value="1">1x</span>
                            <span class="chip active" data-value="2">2x</span>
                            <span class="chip" data-value="3">3x</span>
                            <span class="chip" data-value="4">4x</span>
                            <span class="chip" data-value="5">5x</span>
                            <input type="hidden" name="pt_frequency_per_week" value="2">
                        </div></div>
                    <div class="mb-3"><label class="form-label">Tingkat keaktifan di luar sesi PT</label>
                        <div class="chips" data-chip-group="activity_level">
                            @foreach($activities as $a)<span class="chip {{ $a === 'Cukup Aktif' ? 'active' : '' }}" data-value="{{ $a }}">{{ $a }}</span>@endforeach
                            <input type="hidden" name="activity_level" value="Cukup Aktif">
                        </div>
                        <div class="form-text">Dipakai bersama frekuensi sesi untuk menentukan target protein.</div></div>
                    <div class="mb-3"><label class="form-label">Paket sesi (jumlah)</label>
                        <input name="remaining_sessions" type="number" min="0" value="3" class="form-control" inputmode="numeric" required></div>
                    <div class="mb-1"><label class="form-label">Catatan <small class="text-secondary fw-normal">opsional</small></label>
                        <textarea name="notes" rows="2" class="form-control" placeholder="Cedera, preferensi, dll."></textarea></div>
                </div>

                <div class="wstep d-none" data-step="3">
                    <div class="protein-box mb-3">
                        <div class="small fw-bold text-secondary mb-1">Target protein harian</div>
                        <div><span class="big" id="proteinValue">0g – 0g</span> <span class="fw-bold">/ hari</span></div>
                        <div class="small text-secondary mt-1" id="proteinRate"></div>
                        <hr class="my-2" style="border-color:var(--border)">
                        <div class="small fw-bold mb-1">Panduan porsi makanan</div>
                        <div class="small" id="proteinFood"></div>
                    </div>
                    <div class="soft-card small">
                        <i class="bi bi-stars text-danger"></i> AI akan membuat program latihan <b id="sumFreq">2</b> sesi (1 siklus) memakai <b>{{ $equipmentCount }} alat</b> yang tersedia di gym.
                        <div class="text-secondary mt-1">Prosesnya bisa memakan waktu beberapa detik.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-soft" id="wBack">Kembali</button>
                <button type="button" class="btn btn-pink" id="wNext">Lanjut</button>
                <button type="submit" class="btn btn-pink d-none" id="wSubmit"><span class="label">Buatkan Gerakan & Simpan Klien</span></button>
            </div>
        </form>
    </div>
</div>

{{-- Edit data klien --}}
<div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down" style="max-width:540px">
        <form class="modal-content" method="POST" id="editForm">
            @csrf @method('PATCH')
            <input type="hidden" name="_form" value="edit">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="editTitle">Edit Klien ✏️</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Nama klien</label>
                    <input name="name" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Nomor WhatsApp</label>
                    <input name="phone" class="form-control" inputmode="tel" required></div>
                <div class="mb-3"><label class="form-label">Gender</label>
                    <div class="chips" data-chip-group="gender">
                        <span class="chip" data-value="Wanita">👩 Wanita</span>
                        <span class="chip" data-value="Pria">👨 Pria</span>
                        <input type="hidden" name="gender" value="Wanita">
                    </div></div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label">Berat (kg)</label>
                        <input name="weight_kg" type="number" step="0.1" min="20" class="form-control" inputmode="decimal" required></div>
                    <div class="col-6"><label class="form-label">Tinggi (cm) <small class="text-secondary fw-normal">opsional</small></label>
                        <input name="height_cm" type="number" class="form-control" inputmode="numeric"></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label">Umur (tahun)</label>
                        <input name="age" type="number" min="5" max="100" class="form-control" inputmode="numeric" placeholder="Contoh: 25" required></div>
                    <div class="col-6"><label class="form-label">Lingkar perut (cm) <small class="text-secondary fw-normal">opsional</small></label>
                        <input name="waist_cm" type="number" min="40" max="250" class="form-control" inputmode="numeric" placeholder="Boleh dikosongkan"></div>
                </div>
                <div class="mb-3"><label class="form-label">Tujuan utama</label>
                    <div class="chips" data-chip-group="goal">
                        @foreach($goals as $g)<span class="chip" data-value="{{ $g }}">{{ $g }}</span>@endforeach
                        <input type="hidden" name="goal" value="{{ $goals[0] }}">
                    </div></div>
                <div class="mb-3"><label class="form-label">Alasan / tujuan khusus <small class="text-secondary fw-normal">opsional, bebas ditulis</small></label>
                    <input name="goal_note" class="form-control" maxlength="200" placeholder="Contoh: mau kuat lari jauh, persiapan marathon bulan Oktober"></div>
                <div class="mb-3"><label class="form-label">Fokus bagian tubuh <small class="text-secondary fw-normal">bisa pilih lebih dari satu</small></label>
                    <div class="chips" id="editFocusChips">
                        @foreach($focuses as $f)<span class="chip" data-value="{{ $f }}">{{ $f }}</span>@endforeach
                    </div>
                    <div id="editFocusInputs"></div>
                    <div class="text-danger small mt-1 d-none" id="editFocusErr">Pilih minimal satu fokus ya.</div>
                </div>
                <div class="mb-3"><label class="form-label">Sesi PT per minggu</label>
                    <div class="chips" data-chip-group="pt_frequency_per_week">
                        <span class="chip" data-value="1">1x</span>
                        <span class="chip" data-value="2">2x</span>
                        <span class="chip" data-value="3">3x</span>
                        <span class="chip" data-value="4">4x</span>
                        <span class="chip" data-value="5">5x</span>
                        <input type="hidden" name="pt_frequency_per_week" value="2">
                    </div></div>
                <div class="mb-3"><label class="form-label">Tingkat keaktifan di luar sesi PT</label>
                    <div class="chips" data-chip-group="activity_level">
                        @foreach($activities as $a)<span class="chip" data-value="{{ $a }}">{{ $a }}</span>@endforeach
                        <input type="hidden" name="activity_level" value="Cukup Aktif">
                    </div></div>
                <div class="mb-3"><label class="form-label">Sisa paket sesi</label>
                    <input name="remaining_sessions" type="number" min="0" class="form-control" inputmode="numeric" required></div>
                <div class="mb-3"><label class="form-label">Catatan <small class="text-secondary fw-normal">opsional</small></label>
                    <textarea name="notes" rows="2" class="form-control" placeholder="Cedera, preferensi, dll."></textarea></div>

                <div class="protein-box mb-3">
                    <div class="small fw-bold text-secondary mb-1">Target protein harian</div>
                    <div><span class="big" id="editProteinValue">-</span> <span class="fw-bold">/ hari</span></div>
                    <div class="small text-secondary mt-1" id="editProteinRate"></div>
                    <hr class="my-2" style="border-color:var(--border)">
                    <div class="small fw-bold mb-1">Panduan porsi makanan</div>
                    <div class="small" id="editProteinFood"></div>
                </div>

                <div class="info-warn">
                    ⚠️ <b>Info:</b> Jika kamu mengedit dan memperbarui data (seperti fokus bagian tubuh, tujuan, atau frekuensi latihan), program gerakan AI klien akan dibuat ulang menyesuaikan pengaturan terbaru.
                    <div class="mt-1 small">Program lama dinonaktifkan, riwayat sesi yang sudah selesai tetap aman. Pilih <b>Simpan Data Saja</b> jika hanya memperbaiki hal kecil seperti nama atau nomor HP.</div>
                </div>
            </div>
            <div class="modal-footer flex-column align-items-stretch gap-2">
                <button type="submit" name="regenerate" value="1" class="btn btn-pink py-2" id="editSaveRegen">Simpan Perubahan &amp; Generate Gerakan Baru ✨</button>
                <button type="submit" name="regenerate" value="0" class="btn btn-soft py-2" id="editSaveOnly">Simpan Data Saja</button>
                <button type="button" class="btn btn-link text-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
            </div>
        </form>
    </div>
</div>

{{-- Program klien --}}
<div class="modal fade" id="programModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable" style="max-width:540px">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title fw-bold" id="programTitle">Program</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="programBody"></div>
        </div>
    </div>
</div>

{{-- Ganti satu gerakan dengan gerakan lain satu area otot --}}
<div class="modal fade" id="swapExerciseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable" style="max-width:540px">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title fw-bold" id="swapExerciseTitle">Ganti Gerakan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="swapExerciseBody"></div>
        </div>
    </div>
</div>

{{-- Lihat foto alat (dari modal program) --}}
<div class="modal fade" id="equipmentImageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:540px">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title fw-bold" id="equipmentImageTitle">Foto Alat</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body text-center"><img id="equipmentImageImg" class="img-fluid rounded-4" alt="Foto alat gym"></div>
        </div>
    </div>
</div>

{{-- Lihat contoh gerakan (dari modal program) --}}
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

{{-- History sesi & progres klien --}}
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down" style="max-width:540px">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title fw-bold" id="historyTitle">History</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="historyBody"></div>
        </div>
    </div>
</div>

{{-- Update berat badan --}}
<div class="modal fade" id="weightModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:420px">
        <form class="modal-content" method="POST" id="weightForm">@csrf @method('PATCH')
            <div class="modal-header"><h5 class="modal-title fw-bold" id="weightTitle">Berat badan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <label class="form-label">Berat badan terbaru (kg)</label>
                <input type="number" name="weight_kg" step="0.1" min="20" class="form-control mb-1" inputmode="decimal" id="weightInput" required>
                <div class="form-text mb-3">Target protein harian otomatis dihitung ulang.</div>
                <div class="fw-bold small mb-2" style="color:var(--muted)">Riwayat</div>
                <div id="weightHistory"></div>
            </div>
            <div class="modal-footer"><button class="btn btn-pink w-100">Simpan Berat Badan</button></div>
        </form>
    </div>
</div>

{{-- Tambah paket --}}
<div class="modal fade" id="sessionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <form class="modal-content" method="POST" id="sessionForm">@csrf
            <div class="modal-header"><h5 class="modal-title fw-bold" id="sessionTitle">Tambah paket</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><label class="form-label">Jumlah sesi ditambahkan</label>
                <input type="number" name="sessions" min="1" value="1" class="form-control" required></div>
            <div class="modal-footer"><button class="btn btn-pink w-100">Tambah</button></div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // Peta nama alat => URL foto alat (hanya alat yang punya foto).
    const EQUIPMENT_IMAGES = @json($equipmentImages ?? []);
    // Peta kunci nama gerakan => {url, kind} contoh gerakan (hanya gerakan yang punya media).
    const EXERCISE_MEDIA = @json($exerciseMedia ?? []);
    // Kunci pencocokan nama gerakan; wajib identik dengan ExerciseMedia::keyFor() di server.
    const mvKey = s => String(s ?? '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim().replace(/\s+/g, ' ');

    // --- Kartu klien ringkas: ketuk kepala kartu (nama + WA) untuk membuka detail lengkap ke bawah ---
    document.querySelectorAll('[data-client-toggle]').forEach(head => {
        head.addEventListener('click', () => {
            const card = head.closest('[data-client-card]');
            const open = card.classList.toggle('open');
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    // --- Cari klien: saring kartu berdasarkan nama, langsung saat mengetik ---
    const clientSearch = document.getElementById('clientSearch');
    if (clientSearch) clientSearch.addEventListener('input', () => {
        const q = clientSearch.value.trim().toLowerCase();
        let visible = 0;
        document.querySelectorAll('[data-client-card]').forEach(card => {
            const show = !q || (card.dataset.name || '').includes(q);
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        document.getElementById('clientSearchTerm').textContent = clientSearch.value.trim();
        document.getElementById('clientSearchEmpty').classList.toggle('d-none', visible > 0);
    });

    // --- Choice chips ---
    document.querySelectorAll('[data-chip-group]').forEach(g => g.addEventListener('click', e => {
        const c = e.target.closest('.chip'); if (!c) return;
        g.querySelectorAll('.chip').forEach(x => x.classList.remove('active'));
        c.classList.add('active');
        g.querySelector('input[type=hidden]').value = c.dataset.value;
        recalc(g.closest('form'));
    }));

    // --- Fokus tubuh (multi-select). "Seluruh Tubuh" menggantikan pilihan spesifik, dan sebaliknya ---
    // Dipakai untuk form klien baru dan form edit (id elemen berbeda).
    function bindFocus(boxId, inputsId, errId) {
        const box = document.getElementById(boxId);
        const sync = () => {
            document.getElementById(inputsId).innerHTML = [...box.querySelectorAll('.chip.active')]
                .map(c => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'body_focus[]'; i.value = c.dataset.value; return i.outerHTML; }).join('');
            document.getElementById(errId).classList.add('d-none');
            recalc(box.closest('form'));
        };
        box.addEventListener('click', e => {
            const c = e.target.closest('.chip'); if (!c) return;
            if (c.dataset.value === 'Seluruh Tubuh') {
                box.querySelectorAll('.chip').forEach(x => x.classList.toggle('active', x === c));
            } else {
                c.classList.toggle('active');
                box.querySelector('[data-value="Seluruh Tubuh"]').classList.remove('active');
            }
            sync();
        });
        return { box, sync };
    }
    const focusBox = bindFocus('focusChips', 'focusInputs', 'focusErr').box;
    const editFocus = bindFocus('editFocusChips', 'editFocusInputs', 'editFocusErr');

    // --- Kalkulator protein (sama dengan backend) ---
    const form = document.getElementById('wizardForm');
    // Matriks & penyesuaian keaktifan dikirim dari PHP (sumber tunggal, sama persis dengan backend)
    const MATRIX = @json(\App\Models\Client::PROTEIN_MATRIX);
    const ACTIVITY_SHIFT = @json(\App\Models\Client::ACTIVITIES);
    const food = g => {
        const ayam = Math.round(g * 0.45 / 31 * 100 / 10) * 10;
        const telur = Math.max(1, Math.round(g * 0.2 / 6));
        const tempe = Math.round(g * 0.15 / 19 * 100 / 10) * 10;
        return `±<b>${ayam} g dada ayam</b> + <b>${telur} butir telur</b> + <b>${tempe} g tempe</b>`;
    };
    // Hitung & tampilkan rentang protein untuk satu form (klien baru atau edit)
    function renderProtein(formEl, ids) {
        const f = formEl.elements;
        const weight = parseFloat(f.weight_kg.value) || 0;
        const freq = Math.max(1, Math.min(3, parseInt(f.pt_frequency_per_week.value) || 1));
        const tier = Math.max(1, Math.min(3, freq + (ACTIVITY_SHIFT[f.activity_level.value] || 0)));
        const [kMin, kMax] = (MATRIX[f.goal.value]?.[f.gender.value] || MATRIX['Bugar & Sehat']['Wanita'])[tier - 1];
        // Aritmetika bilangan bulat (x10) supaya hasilnya identik dengan perhitungan di server
        const w10 = Math.round(weight * 10);
        const calc = k => Math.floor((w10 * Math.round(k * 10)) / 100 + 0.5);
        const gMin = calc(kMin), gMax = calc(kMax);
        document.getElementById(ids.value).textContent = weight ? `${gMin}g – ${gMax}g` : '-';
        document.getElementById(ids.rate).textContent = weight
            ? `Batas minimal aman: ${gMin}g | Target optimal: ${gMax}g  (${kMin} – ${kMax} g/kg)`
            : '';
        document.getElementById(ids.food).innerHTML = weight
            ? `🍗 <b>Minimal (${gMin}g):</b> ${food(gMin)}<br>🍗 <b>Optimal (${gMax}g):</b> ${food(gMax)}<br><span class="text-secondary">Sisanya dari nasi, susu, dan sayur.</span>`
            : 'Isi berat badan dulu ya.';
    }
    const calcProtein = () => {
        renderProtein(form, { value: 'proteinValue', rate: 'proteinRate', food: 'proteinFood' });
        document.getElementById('sumFreq').textContent = form.elements.pt_frequency_per_week.value;
    };
    const editForm = document.getElementById('editForm');
    const calcEdit = () => renderProtein(editForm, { value: 'editProteinValue', rate: 'editProteinRate', food: 'editProteinFood' });
    function recalc(formEl) { if (formEl === form) calcProtein(); else if (formEl === editForm) calcEdit(); }
    form.elements.weight_kg.addEventListener('input', calcProtein);
    editForm.elements.weight_kg.addEventListener('input', calcEdit);

    // --- Wizard ---
    let step = 1;
    const steps = [...form.querySelectorAll('.wstep')], bars = form.querySelectorAll('.wizard-steps i');
    const back = document.getElementById('wBack'), next = document.getElementById('wNext'), submit = document.getElementById('wSubmit');
    function show() {
        steps.forEach(s => s.classList.toggle('d-none', +s.dataset.step !== step));
        bars.forEach((b, i) => b.classList.toggle('on', i < step));
        back.style.visibility = step === 1 ? 'hidden' : 'visible';
        next.classList.toggle('d-none', step === 3);
        submit.classList.toggle('d-none', step !== 3);
        if (step === 3) calcProtein();
    }
    next.addEventListener('click', () => {
        const inputs = steps[step - 1].querySelectorAll('input:not([type=hidden]),textarea');
        for (const i of inputs) if (!i.reportValidity()) return;
        if (step === 2 && !focusBox.querySelector('.chip.active')) { document.getElementById('focusErr').classList.remove('d-none'); return; }
        step++; show();
    });
    back.addEventListener('click', () => { step--; show(); });
    form.addEventListener('submit', () => {
        submit.disabled = true;
        submit.innerHTML = '<span class="spinner-border spinner-border-sm"></span> AI sedang menyusun program...';
    });
    show();

    // --- Modal edit klien: isi otomatis dari data klien ---
    document.getElementById('editModal').addEventListener('show.bs.modal', e => {
        if (!e.relatedTarget || !e.relatedTarget.dataset.client) return;
        const d = JSON.parse(e.relatedTarget.dataset.client), f = editForm.elements;
        editForm.action = '/klien/' + d.id;
        document.getElementById('editTitle').textContent = 'Edit ' + d.name + ' ✏️';
        f.name.value = d.name; f.phone.value = d.phone; f.age.value = d.age ?? ''; f.weight_kg.value = d.weight_kg;
        f.height_cm.value = d.height_cm ?? ''; f.waist_cm.value = d.waist_cm ?? ''; f.goal_note.value = d.goal_note ?? '';
        f.remaining_sessions.value = d.remaining_sessions; f.notes.value = d.notes ?? '';
        editForm.querySelectorAll('[data-chip-group]').forEach(g => {
            const val = String(d[g.dataset.chipGroup]);
            g.querySelectorAll('.chip').forEach(c => c.classList.toggle('active', c.dataset.value === val));
            g.querySelector('input[type=hidden]').value = val;
        });
        const focus = d.body_focus && d.body_focus.length ? d.body_focus : ['Seluruh Tubuh'];
        editFocus.box.querySelectorAll('.chip').forEach(c => c.classList.toggle('active', focus.includes(c.dataset.value)));
        editFocus.sync();
        document.getElementById('editSaveRegen').disabled = false;
        document.getElementById('editSaveOnly').disabled = false;
        document.getElementById('editSaveRegen').innerHTML = 'Simpan Perubahan &amp; Generate Gerakan Baru ✨';
        calcEdit();
    });
    editForm.addEventListener('submit', e => {
        if (!editFocus.box.querySelector('.chip.active')) {
            e.preventDefault(); document.getElementById('editFocusErr').classList.remove('d-none'); return;
        }
        const regen = e.submitter && e.submitter.value === '1';
        // Ditunda agar nilai tombol yang ditekan sudah terkirim sebelum tombol dinonaktifkan
        setTimeout(() => {
            document.getElementById('editSaveOnly').disabled = true;
            const b = document.getElementById('editSaveRegen'); b.disabled = true;
            if (regen) b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> AI sedang menyusun program...';
        }, 0);
    });

    // --- Modal History: data diambil saat dibuka (ringan, tidak ikut dimuat di halaman daftar) ---
    document.getElementById('historyModal').addEventListener('show.bs.modal', async e => {
        const b = e.relatedTarget; if (!b || !b.dataset.id) return;
        const body = document.getElementById('historyBody');
        document.getElementById('historyTitle').textContent = 'History ' + b.dataset.name;
        body.innerHTML = '<div class="text-center text-secondary py-5"><span class="spinner-border spinner-border-sm"></span> Memuat riwayat...</div>';
        const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
        const fmtKg = n => (+n).toString().replace(/\.0$/, '');
        try {
            const d = await api('/klien/' + b.dataset.id + '/riwayat');
            if (!document.body.contains(body)) return; // halaman sudah berpindah (Turbo)
            if (!d.total) {
                body.innerHTML = '<div class="text-center text-secondary py-5">Belum ada riwayat sesi selesai untuk klien ini. Yuk mulai sesi pertamanya dari Kalender! 🌸</div>';
                return;
            }
            const summary = `<div class="protein-box mb-3"><div class="row g-2 text-center">
                <div class="col-6"><div class="big">${d.total}</div><div class="small text-secondary fw-bold">Sesi selesai</div></div>
                <div class="col-6"><div class="big">${d.remaining}</div><div class="small text-secondary fw-bold">Sisa paket</div></div></div>
                <hr class="my-2" style="border-color:var(--border)">
                <div class="small text-center"><i class="bi bi-calendar-heart"></i> Latihan pertama <b>${esc(d.first)}</b> &rarr; terakhir <b>${esc(d.last)}</b></div></div>`;
            const items = d.sessions.map(s => `
                <div class="client-card mb-3">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                        <div><div class="fw-bold">${esc(s.date_label)}</div>
                            <div class="small text-secondary"><i class="bi bi-clock"></i> ${esc(s.time)} &middot; ${s.duration ?? '-'} menit</div></div>
                        <span class="badge bg-success-subtle text-success rounded-pill">Selesai</span>
                    </div>
                    <div class="fw-bold mb-2" style="color:var(--pink-dark)">${esc(s.program)}</div>
                    ${s.exercises.length ? s.exercises.map(x => {
                        const dl = x.delta === null || x.delta === 0 ? '' :
                            `<span style="font-size:.7rem;font-weight:800;color:${x.delta > 0 ? '#16a34a' : '#d97706'}">${x.delta > 0 ? '▲ +' : '▼ '}${fmtKg(x.delta)} kg</span>`;
                        return `<div class="d-flex justify-content-between align-items-center gap-2 py-1 border-bottom small">
                            <div><b>${esc(x.name)}</b>${x.equipment ? ` <span class="text-secondary" style="font-size:.7rem">(${esc(x.equipment)})</span>` : ''}<div class="text-secondary" style="font-size:.74rem">${esc(x.sets_label || '-')}</div>${x.detail_label ? `<div class="text-secondary" style="font-size:.72rem">${esc(x.detail_label)}</div>` : ''}</div>
                            <div class="text-end"><span class="badge rounded-pill" style="background:var(--pink)">${x.kg ? fmtKg(x.kg) + ' kg' : 'Body weight'}</span><div>${dl}</div></div></div>`;
                    }).join('') : '<div class="small text-secondary">Tidak ada rincian gerakan.</div>'}
                    ${s.coach_note ? `<div class="mt-2 small fst-italic p-2 rounded-3" style="background:var(--surface);border-left:3px solid var(--pink)">“${esc(s.coach_note)}”<div class="fst-normal fw-bold" style="color:var(--pink-dark);font-size:.7rem">- Catatan Coach</div></div>` : ''}
                    ${s.share_url ? `<a href="${esc(s.share_url)}" target="_blank" rel="noopener" data-turbo="false" class="btn btn-soft btn-sm w-100 mt-2">Lihat Kartu IG Story 📸</a>` : ''}
                </div>`).join('');
            body.innerHTML = summary + items;
        } catch (err) {
            body.innerHTML = '<div class="text-center text-danger py-5">Riwayat belum bisa dimuat. Coba lagi ya.</div>';
        }
    });

    // --- Modal program ---
    let programData = null, programSourceBtn = null;
    const escP = s => String(s ?? '').replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
    function renderPrograms() {
        document.getElementById('programBody').innerHTML = programData.programs.map(p => `
            <div class="mb-3"><div class="fw-bold mb-2" style="color:var(--pink-dark)">${escP(p.day_label)}</div>
            ${p.exercises_json.map((x, xi) => `<div class="ex-row"><button type="button" class="btn btn-soft btn-sm float-end ms-1" title="Ganti gerakan ini dengan gerakan lain satu otot" data-swap-ex data-program="${p.id}" data-index="${xi}"><i class="bi bi-arrow-repeat"></i> Ganti</button>${EXERCISE_MEDIA[mvKey(x.name)] ? `<button type="button" class="btn btn-soft btn-sm float-end ms-1" title="Lihat contoh gerakan" data-mv-play="${escP(x.name)}"><i class="bi bi-play-circle"></i></button>` : ''}${EQUIPMENT_IMAGES[x.equipment] ? `<button type="button" class="btn btn-soft btn-sm float-end" title="Lihat foto alat" data-eq-photo="${escP(x.equipment)}"><i class="bi bi-eye"></i></button>` : ''}<div class="fw-bold">${escP(x.name)}</div>
                <div class="small">${escP(x.sets_reps)} &middot; ${x.target_kg > 0 ? escP(x.target_kg) + ' kg' : 'berat badan'} &middot; <span class="text-secondary">${escP(x.equipment)}</span></div>
                <div class="tip">💡 ${escP(x.tip)}</div></div>`).join('')}</div>`).join('') || '<div class="text-secondary">Belum ada program.</div>';
    }
    document.getElementById('programModal').addEventListener('show.bs.modal', e => {
        programSourceBtn = e.relatedTarget;
        programData = JSON.parse(e.relatedTarget.dataset.client);
        document.getElementById('programTitle').textContent = 'Program ' + programData.name;
        renderPrograms();
    });
    document.getElementById('programBody').addEventListener('click', e => {
        const b = e.target.closest('[data-swap-ex]'); if (!b) return;
        const pid = +b.dataset.program, idx = +b.dataset.index;
        openSwap(pid, idx, (exercise, exercises) => {
            const p = programData.programs.find(x => +x.id === pid);
            if (p) p.exercises_json = exercises;
            if (programSourceBtn) programSourceBtn.dataset.client = JSON.stringify(programData);
            renderPrograms();
        });
    });

    // --- Ganti Gerakan: ganti SATU gerakan dengan gerakan lain satu area otot (bukan generate ulang) ---
    let swapCtx = null;
    async function openSwap(programId, index, onDone) {
        swapCtx = { programId, index, onDone };
        const body = document.getElementById('swapExerciseBody');
        document.getElementById('swapExerciseTitle').textContent = 'Ganti Gerakan';
        body.innerHTML = '<div class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm"></span> Mencari gerakan pengganti...</div>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('swapExerciseModal')).show();
        try {
            const d = await api(`/program/${programId}/gerakan/${index}/alternatif`);
            if (!swapCtx) return;
            const cur = d.current;
            let html = cur ? `<div class="small text-secondary mb-1">Gerakan sekarang:</div><div class="ex-row mb-3"><div class="fw-bold">${escP(cur.name)}</div><div class="small">${escP(cur.sets_reps)} &middot; ${escP(cur.equipment)}</div></div><div class="small text-secondary mb-1">Pilih pengganti (otot yang sama, alat yang tersedia):</div>` : '';
            html += d.alternatives.length ? d.alternatives.map(a => `
                <button type="button" class="btn btn-soft w-100 text-start mb-2" data-swap-pick="${escP(a.name)}">
                    <span class="fw-bold d-block">${escP(a.name)}</span>
                    <span class="small text-secondary">${escP(a.sets_reps)}${a.target_kg > 0 ? ' &middot; ' + escP(a.target_kg) + ' kg' : ''} &middot; ${escP(a.equipment)}</span>
                </button>`).join('')
                : '<div class="text-center text-secondary py-3">Belum ada gerakan alternatif lain untuk otot ini dengan alat yang tersedia.</div>';
            swapCtx.shown = [cur?.name, ...d.alternatives.map(a => a.name)].filter(Boolean);
            swapCtx.aiByName = {};
            html += `<div class="mt-3"><div data-swap-ai-list></div>
                <button type="button" class="btn btn-pink w-100" data-swap-ai>✨ Buatkan gerakan baru dengan AI</button>
                <div class="small text-secondary mt-1 text-center">AI membuat satu gerakan baru khusus posisi ini: otot yang sama, alat yang tersedia, beda dari yang sudah ada. Ketuk lagi untuk variasi lain.</div></div>`;
            body.innerHTML = html;
        } catch (err) {
            body.innerHTML = '<div class="text-center text-danger py-4">Pengganti belum bisa dimuat. Coba lagi ya.</div>';
        }
    }
    document.getElementById('swapExerciseBody').addEventListener('click', async e => {
        const aiBtn = e.target.closest('[data-swap-ai]');
        if (aiBtn && swapCtx) {
            aiBtn.disabled = true;
            const label = aiBtn.innerHTML;
            aiBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> AI sedang membuat gerakan...';
            try {
                const d = await api(`/program/${swapCtx.programId}/gerakan/${swapCtx.index}/alternatif-ai`, 'POST', { exclude: swapCtx.shown || [] });
                const a = d.exercise;
                (swapCtx.aiByName ??= {})[a.name] = a;
                (swapCtx.shown ??= []).push(a.name);
                document.querySelector('#swapExerciseBody [data-swap-ai-list]').insertAdjacentHTML('afterbegin', `
                <button type="button" class="btn btn-soft w-100 text-start mb-2" data-swap-pick-ai="${escP(a.name)}">
                    <span class="fw-bold d-block">${escP(a.name)} <span class="badge rounded-pill" style="background:var(--pink)">Baru dari AI</span></span>
                    <span class="small text-secondary">${escP(a.sets_reps)}${a.target_kg > 0 ? ' &middot; ' + escP(a.target_kg) + ' kg' : ''} &middot; ${escP(a.equipment)}</span>
                </button>`);
            } catch (err) { toast(err.message || 'AI belum bisa membuat gerakan baru.', 'danger'); }
            aiBtn.disabled = false; aiBtn.innerHTML = label;
            return;
        }
        const bAi = e.target.closest('[data-swap-pick-ai]');
        const b = e.target.closest('[data-swap-pick]');
        if ((!b && !bAi) || !swapCtx) return;
        const { programId, index, onDone } = swapCtx;
        const btn = bAi || b;
        btn.disabled = true;
        try {
            const payload = bAi ? { exercise: swapCtx.aiByName[bAi.dataset.swapPickAi] } : { name: b.dataset.swapPick };
            const res = await api(`/program/${programId}/gerakan/${index}`, 'PATCH', payload);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('swapExerciseModal')).hide();
            toast(res.message);
            if (onDone) onDone(res.exercise, res.exercises);
        } catch (err) { toast(err.message || 'Gagal mengganti gerakan.', 'danger'); btn.disabled = false; }
        swapCtx = null;
    });

    // --- Lihat foto alat dari modal program (ikon mata; hanya muncul bila alatnya punya foto) ---
    document.getElementById('programBody').addEventListener('click', e => {
        const b = e.target.closest('[data-eq-photo]'); if (!b) return;
        const url = EQUIPMENT_IMAGES[b.dataset.eqPhoto]; if (!url) return;
        document.getElementById('equipmentImageTitle').textContent = 'Foto ' + b.dataset.eqPhoto;
        document.getElementById('equipmentImageImg').src = url;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('equipmentImageModal')).show();
    });

    // --- Lihat contoh gerakan dari modal program (ikon putar; hanya muncul bila gerakannya punya media) ---
    document.getElementById('programBody').addEventListener('click', e => {
        const b = e.target.closest('[data-mv-play]'); if (!b) return;
        const m = EXERCISE_MEDIA[mvKey(b.dataset.mvPlay)]; if (!m) return;
        document.getElementById('exerciseMediaTitle').textContent = 'Gerakan ' + b.dataset.mvPlay;
        const cr = document.getElementById('exerciseMediaCredit');
        if (m.credit) { cr.textContent = m.credit; cr.classList.remove('d-none'); } else { cr.textContent = ''; cr.classList.add('d-none'); }
        const vid = document.getElementById('exerciseMediaVideo'), img = document.getElementById('exerciseMediaImg');
        if (m.kind === 'video') { img.classList.add('d-none'); img.removeAttribute('src'); vid.classList.remove('d-none'); vid.src = m.url; vid.play().catch(() => {}); }
        else { vid.classList.add('d-none'); vid.pause(); vid.removeAttribute('src'); img.classList.remove('d-none'); img.src = m.url; }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('exerciseMediaModal')).show();
    });

    // Modal contoh gerakan ditutup: hentikan video agar tidak tetap berbunyi/berputar di latar.
    document.getElementById('exerciseMediaModal').addEventListener('hidden.bs.modal', () => {
        const vid = document.getElementById('exerciseMediaVideo');
        vid.pause(); vid.removeAttribute('src'); vid.load();
        document.getElementById('exerciseMediaImg').removeAttribute('src');
    });

    // --- Modal berat badan ---
    document.getElementById('weightModal').addEventListener('show.bs.modal', e => {
        const b = e.relatedTarget, logs = JSON.parse(b.dataset.logs || '[]');
        document.getElementById('weightTitle').textContent = 'Berat badan ' + b.dataset.name;
        document.getElementById('weightForm').action = '/klien/' + b.dataset.id + '/berat';
        document.getElementById('weightInput').value = b.dataset.weight;
        const rows = (logs.length ? logs : [{ d: 'Saat daftar', w: b.dataset.weight }]).slice().reverse();
        document.getElementById('weightHistory').innerHTML = rows.map((l, i) => {
            const prev = rows[i + 1], diff = prev ? +(l.w - prev.w).toFixed(1) : null;
            const color = diff === null || diff === 0 ? 'var(--muted)' : (diff < 0 ? '#16a34a' : '#db2777');
            return `<div class="d-flex justify-content-between py-1 border-bottom small"><span class="text-secondary">${l.d}</span>
                <b>${l.w} kg <span style="color:${color}">${diff ? '(' + (diff > 0 ? '+' : '') + diff + ')' : ''}</span></b></div>`;
        }).join('');
    });

    // --- Modal tambah paket ---
    document.getElementById('sessionModal').addEventListener('show.bs.modal', e => {
        const b = e.relatedTarget;
        document.getElementById('sessionTitle').textContent = 'Tambah paket ' + b.dataset.name;
        document.getElementById('sessionForm').action = '/klien/' + b.dataset.id + '/sesi';
    });

    @if($errors->any() && old('_form') !== 'edit')
        new bootstrap.Modal('#wizardModal').show();
    @endif
})();
</script>
@endpush
