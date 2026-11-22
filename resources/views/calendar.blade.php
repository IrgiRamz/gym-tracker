@extends('layouts.app')
@section('title', 'Kalender - Gym Tracker by Neil')

@section('content')
<div class="cal-head">
    <button class="cal-nav" id="prevMonth" aria-label="Bulan sebelumnya">&lt;</button>
    <div class="month" id="monthLabel"></div>
    <button class="cal-nav" id="nextMonth" aria-label="Bulan berikutnya">&gt;</button>
</div>
<div class="cal-grid mb-1">
    @foreach(['Sen','Sel','Rab','Kam','Jum','Sab','Min'] as $d)<div class="cal-dow">{{ $d }}</div>@endforeach
</div>
<div class="cal-grid" id="calGrid"></div>

<div class="d-flex justify-content-between align-items-center mt-4 mb-2">
    <div>
        <div class="fw-bold" id="agendaTitle">Agenda</div>
        <div class="small text-secondary" id="agendaSub"></div>
    </div>
    <button class="btn btn-pink btn-sm" id="addBtn"><i class="bi bi-plus-lg"></i> Tambah Jadwal</button>
</div>
<div id="agendaList"></div>

<div id="runningBanner" class="d-none mt-3">
    <div class="soft-card d-flex align-items-center justify-content-between" style="border-color:#fca5a5;background:#fef2f2">
        <div><span class="live-badge">LIVE</span> <span class="fw-bold ms-2" id="runningName"></span></div>
        <button class="btn btn-sm btn-pink" id="resumeBtn">Buka</button>
    </div>
</div>

{{-- Modal tambah jadwal --}}
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
        <form class="modal-content" id="addForm">
            <div class="modal-header"><h5 class="modal-title fw-bold">Tambah Jadwal 📅</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Klien</label>
                    <select class="form-select" id="fClient" required>
                        <option value="">Pilih klien...</option>
                    </select>
                    <div class="form-text">💡 Klien dengan sisa sesi 0 tidak dapat dijadwalkan. Silakan tambah/top-up sisa sesi terlebih dahulu lewat menu Klien -&gt; Edit.</div></div>
                <div class="mb-3"><label class="form-label">Program</label>
                    <select class="form-select" id="fProgram"><option value="">Otomatis (urut Sesi A, B, ...)</option></select></div>
                <div class="row g-2">
                    <div class="col-7"><label class="form-label">Tanggal</label><input type="date" class="form-control" id="fDate" required></div>
                    <div class="col-5"><label class="form-label">Jam</label><input type="time" class="form-control" id="fTime" value="09:00" required></div>
                </div>
                <div class="text-danger small mt-2 d-none" id="addErr"></div>
            </div>
            <div class="modal-footer"><button class="btn btn-pink w-100" id="addSubmit">Simpan Jadwal</button></div>
        </form>
    </div>
</div>

{{-- Panel sesi berjalan --}}
<div class="live-panel" id="livePanel">
    <div class="live-sheet">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="live-badge">Sesi Sedang Berjalan</span>
            <button class="btn btn-sm btn-soft" id="minimizeLive"><i class="bi bi-chevron-down"></i> Kecilkan</button>
        </div>
        <div class="text-center mt-2">
            <div class="fw-bold fs-5" id="liveClient"></div>
            <div class="small text-secondary" id="liveProgram"></div>
        </div>
        <div class="stopwatch my-2" id="stopwatch">00:00:00</div>
        <div id="liveExercises"></div>
        <div class="mb-3">
            <label class="form-label">Catatan penyemangat dari PT 💌</label>
            <textarea id="liveNote" rows="3" class="form-control" placeholder="Contoh: Postur squat kamu makin bagus! Terus semangat ya."></textarea>
        </div>
        <div class="live-sticky-cta">
            <button class="btn btn-pink w-100 py-3" id="finishBtn">Selesai Sesi &amp; Kirim ke WA 💖</button>
            <button class="btn btn-outline-secondary w-100 mt-2 rounded-4" id="cancelLiveBtn">Batalkan Sesi (salah klik)</button>
        </div>
    </div>
</div>

{{-- Lihat foto alat (dari live session) --}}
<div class="modal fade" id="equipmentImageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:540px">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title fw-bold" id="equipmentImageTitle">Foto Alat</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body text-center"><img id="equipmentImageImg" class="img-fluid rounded-4" alt="Foto alat gym"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const CLIENTS = @json($clients);
    // Peta nama alat => URL foto alat (hanya alat yang punya foto).
    const EQUIPMENT_IMAGES = @json($equipmentImages ?? []);
    const MONTHS = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    const DAYS = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    const pad = n => String(n).padStart(2, '0');
    const iso = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));

    const today = new Date();
    let view = new Date(today.getFullYear(), today.getMonth(), 1);
    let selected = iso(today);
    let items = [];
    let live = null, timer = null;

    // Turbo: hentikan stopwatch saat pindah halaman (sesi tetap berjalan di server;
    // saat kembali ke Kalender, sesi berjalan dilanjutkan otomatis dari /jadwal/berjalan).
    document.addEventListener('turbo:before-render', () => clearInterval(timer), { once: true });

    async function load() {
        const m = `${view.getFullYear()}-${pad(view.getMonth() + 1)}`;
        items = await api('{{ route('jadwal.index') }}?month=' + m);
        render();
    }

    function render() {
        if (!document.getElementById('calGrid')) return; // halaman sudah berpindah (Turbo)
        document.getElementById('monthLabel').textContent = `${MONTHS[view.getMonth()]} ${view.getFullYear()}`;
        const grid = document.getElementById('calGrid');
        const first = new Date(view.getFullYear(), view.getMonth(), 1);
        const total = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
        const offset = (first.getDay() + 6) % 7; // Senin = 0
        let html = '';
        for (let i = 0; i < offset; i++) html += '<div class="cal-day empty"></div>';
        for (let d = 1; d <= total; d++) {
            const key = `${view.getFullYear()}-${pad(view.getMonth() + 1)}-${pad(d)}`;
            const day = items.filter(s => s.date === key && s.status !== 'cancelled');
            const hasPink = day.some(s => s.status !== 'completed'), hasDone = day.some(s => s.status === 'completed');
            html += `<button type="button" class="cal-day ${key === iso(today) ? 'today' : ''} ${key === selected ? 'selected' : ''}" data-date="${key}">${d}
                <span class="dots">${hasPink ? '<i class="dot"></i>' : ''}${hasDone ? '<i class="dot done"></i>' : ''}</span></button>`;
        }
        grid.innerHTML = html;
        renderAgenda();
    }

    function renderAgenda() {
        const d = new Date(selected + 'T00:00:00');
        document.getElementById('agendaTitle').textContent = `${DAYS[d.getDay()]}, ${d.getDate()} ${MONTHS[d.getMonth()]}`;
        const list = items.filter(s => s.date === selected && s.status !== 'cancelled');
        document.getElementById('agendaSub').textContent = list.length ? `${list.length} sesi latihan` : 'Belum ada jadwal';
        document.getElementById('agendaList').innerHTML = list.length ? list.map(s => {
            const done = s.status === 'completed', running = !done && s.started_at;
            return `<div class="agenda-item ${done ? 'done' : ''}">
                <div class="agenda-time">${esc(s.time)}</div>
                <div class="agenda-body"><div class="nm">${esc(s.client_name)}</div>
                    <div class="pg">${esc(s.program_label || 'Tanpa program')}${done ? ' &middot; ' + s.duration_minutes + ' menit' : ''}</div></div>
                ${done ? '<span class="badge bg-success-subtle text-success rounded-pill">Selesai</span>'
                    : `<button class="btn btn-pink btn-sm" data-start="${s.id}">${running ? 'Lanjut' : 'Mulai'}</button>
                       <button class="btn btn-sm text-danger" data-del="${s.id}"><i class="bi bi-trash"></i></button>`}
            </div>`;
        }).join('') : '<div class="text-center text-secondary py-4">Tidak ada jadwal di tanggal ini 🌸</div>';
    }

    document.getElementById('calGrid').addEventListener('click', e => {
        const b = e.target.closest('.cal-day[data-date]'); if (!b) return;
        selected = b.dataset.date; render();
    });
    document.getElementById('prevMonth').onclick = () => { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); load(); };
    document.getElementById('nextMonth').onclick = () => { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); load(); };

    document.getElementById('agendaList').addEventListener('click', async e => {
        const st = e.target.closest('[data-start]'), del = e.target.closest('[data-del]');
        try {
            if (st) {
                const s = await api(`/jadwal/${st.dataset.start}/mulai`, 'POST');
                openLive(s); await load();
            } else if (del && await confirmModal({ title: 'Hapus jadwal ini?', text: 'Jadwal latihan ini akan dihapus dari kalender.', yes: 'Ya, hapus', icon: '🗑️', danger: true })) {
                const gone = items.find(x => x.id == del.dataset.del);
                await api(`/jadwal/${del.dataset.del}`, 'DELETE');
                const cl = gone && CLIENTS.find(x => x.id == gone.client_id); if (cl && cl.booked > 0) cl.booked--;
                await load();
            }
        } catch (err) { toast(err.message, 'danger'); }
    });

    // --- Tambah jadwal ---
    const addModal = new bootstrap.Modal('#addModal');

    // Pilihan klien dengan keterangan sisa sesi. Klien yang sisa sesinya habis, atau yang seluruh
    // sisanya sudah terpakai oleh jadwal 'scheduled', dinonaktifkan (server juga memvalidasi ini).
    function renderClientOptions() {
        const sel = document.getElementById('fClient'), keep = sel.value;
        sel.innerHTML = '<option value="">Pilih klien...</option>' + CLIENTS.map(c => {
            let label, off = false;
            if (c.remaining <= 0) { label = `${c.name} (Sesi Habis - 0)`; off = true; }
            else if (c.booked >= c.remaining) { label = `${c.name} (Sisa ${c.remaining} sesi, semua sudah terjadwal)`; off = true; }
            else label = `${c.name} (Sisa ${c.remaining} sesi)`;
            return `<option value="${c.id}"${off ? ' disabled' : ''}>${esc(label)}</option>`;
        }).join('');
        if ([...sel.options].some(o => o.value === keep && !o.disabled)) sel.value = keep;
    }
    renderClientOptions();

    document.getElementById('addBtn').onclick = () => {
        renderClientOptions();
        document.getElementById('fProgram').innerHTML = '<option value="">Otomatis (urut Sesi A, B, ...)</option>';
        document.getElementById('fDate').value = selected;
        // Tanggal hari ini -> jam otomatis jam sekarang; tanggal lain -> default 09:00
        const now = new Date();
        document.getElementById('fTime').value = selected === iso(now) ? `${pad(now.getHours())}:${pad(now.getMinutes())}` : '09:00';
        document.getElementById('addErr').classList.add('d-none');
        addModal.show();
    };
    // Ganti tanggal di form: sesuaikan jam default lagi (hari ini = jam sekarang)
    document.getElementById('fDate').onchange = e => {
        const now = new Date();
        document.getElementById('fTime').value = e.target.value === iso(now) ? `${pad(now.getHours())}:${pad(now.getMinutes())}` : '09:00';
    };
    document.getElementById('fClient').onchange = e => {
        const c = CLIENTS.find(x => x.id == e.target.value);
        document.getElementById('fProgram').innerHTML = '<option value="">Otomatis (urut Sesi A, B, ...)</option>' +
            (c ? c.programs.map(p => `<option value="${p.id}">${esc(p.label)}</option>`).join('') : '');
    };
    document.getElementById('addForm').onsubmit = async e => {
        e.preventDefault();
        const err = document.getElementById('addErr'), btn = document.getElementById('addSubmit');
        btn.disabled = true;
        try {
            const s = await api('{{ route('jadwal.store') }}', 'POST', {
                client_id: document.getElementById('fClient').value,
                workout_program_id: document.getElementById('fProgram').value || null,
                scheduled_date: document.getElementById('fDate').value,
                scheduled_time: document.getElementById('fTime').value,
            });
            addModal.hide();
            const cl = CLIENTS.find(x => x.id == s.client_id); if (cl) cl.booked++;
            const d = new Date(s.date + 'T00:00:00');
            view = new Date(d.getFullYear(), d.getMonth(), 1); selected = s.date;
            await load(); toast('Jadwal tersimpan ✨');
        } catch (ex) { err.textContent = ex.message; err.classList.remove('d-none'); }
        btn.disabled = false;
    };

    // --- Live session ---
    const panel = document.getElementById('livePanel');

    // Satu baris input per set: repetisi & beban aktual khusus set itu.
    function setRowHtml(repsVal, kgVal) {
        return `<div class="set-row">
            <span class="set-no"></span>
            <div><label>Repetisi</label>
                <input type="text" maxlength="20" inputmode="text" autocomplete="off" class="form-control set-reps" value="${esc(repsVal ?? '')}" aria-label="Repetisi set"></div>
            <div><label>Beban (kg)</label>
                <input type="number" step="0.5" min="0" inputmode="decimal" class="form-control set-kg" value="${kgVal || 0}" aria-label="Beban set dalam kg"></div>
            <button type="button" class="btn btn-sm btn-outline-danger set-remove" title="Hapus set ini"><i class="bi bi-x-lg"></i></button>
        </div>`;
    }
    // Beri nomor "Set 1..n"; tombol hapus disembunyikan bila tinggal 1 set.
    function renumberSets(card) {
        const rows = card.querySelectorAll('.set-row');
        rows.forEach((r, i) => {
            r.querySelector('.set-no').textContent = 'Set ' + (i + 1);
            r.querySelector('.set-remove').style.visibility = rows.length > 1 ? '' : 'hidden';
        });
    }

    function openLive(s) {
        live = s;
        document.getElementById('liveClient').textContent = s.client_name;
        document.getElementById('liveProgram').textContent = s.program_label || 'Latihan bebas';
        document.getElementById('liveNote').value = '';
        document.getElementById('liveExercises').innerHTML = (s.exercises.length ? s.exercises : []).map((x, i) => `
            <div class="ex-row position-relative" data-i="${i}" data-name="${esc(x.name)}" data-equipment="${esc(x.equipment)}" data-sets-reps="${esc(x.sets_reps)}">
                ${EQUIPMENT_IMAGES[x.equipment] ? `<button type="button" class="btn btn-soft btn-sm position-absolute top-0 end-0 m-2" title="Lihat foto alat" data-eq-photo="${esc(x.equipment)}"><i class="bi bi-eye"></i></button>` : ''}
                <div class="fw-bold">${esc(x.name)}</div>
                <div class="small text-secondary mb-2">Target ${esc(x.sets_reps)}${x.target_kg > 0 ? ' &middot; ' + esc(x.target_kg) + ' kg' : ''} &middot; ${esc(x.equipment)}</div>
                <div class="set-rows">
                    ${Array.from({ length: Math.min(Math.max(x.default_sets || 3, 1), 8) }, () => setRowHtml(x.default_reps ?? '', x.target_kg || 0)).join('')}
                </div>
                <button type="button" class="btn btn-soft btn-sm w-100 mt-2 add-set-btn"><i class="bi bi-plus-circle"></i> Tambah Set</button>
                <div class="tip mt-2">💡 ${esc(x.tip)}</div>
            </div>`).join('') || '<div class="text-secondary text-center py-3">Jadwal ini belum punya program gerakan.</div>';
        panel.querySelectorAll('.ex-row').forEach(renumberSets);
        panel.classList.add('show');
        document.body.style.overflow = 'hidden';
        const start = new Date(s.started_at).getTime();
        clearInterval(timer);
        const tick = () => {
            const sec = Math.max(0, Math.floor((Date.now() - start) / 1000));
            const sw = document.getElementById('stopwatch'); if (!sw) return clearInterval(timer);
            sw.textContent = `${pad(Math.floor(sec / 3600))}:${pad(Math.floor(sec % 3600 / 60))}:${pad(sec % 60)}`;
        };
        tick(); timer = setInterval(tick, 1000);
        showBanner();
    }
    function closeLive() { panel.classList.remove('show'); document.body.style.overflow = ''; }

    // Ikon mata di pojok kotak gerakan: lihat foto alat (hanya muncul bila alatnya punya foto).
    document.getElementById('liveExercises').addEventListener('click', e => {
        const b = e.target.closest('[data-eq-photo]'); if (!b) return;
        const url = EQUIPMENT_IMAGES[b.dataset.eqPhoto]; if (!url) return;
        document.getElementById('equipmentImageTitle').textContent = 'Foto ' + b.dataset.eqPhoto;
        document.getElementById('equipmentImageImg').src = url;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('equipmentImageModal')).show();
    });

    // Tambah/kurang baris set per gerakan, fleksibel mengikuti kekuatan klien hari itu.
    // Baris baru menyalin repetisi & beban baris terakhir; minimal tersisa 1 set.
    document.getElementById('liveExercises').addEventListener('click', e => {
        const add = e.target.closest('.add-set-btn');
        if (add) {
            const card = add.closest('.ex-row'), rows = card.querySelector('.set-rows');
            const last = rows.lastElementChild;
            rows.insertAdjacentHTML('beforeend', setRowHtml(
                last ? last.querySelector('.set-reps').value : '',
                last ? last.querySelector('.set-kg').value : 0));
            renumberSets(card);
            return;
        }
        const rm = e.target.closest('.set-remove');
        if (rm) {
            const card = rm.closest('.ex-row');
            if (card.querySelectorAll('.set-row').length > 1) {
                rm.closest('.set-row').remove();
                renumberSets(card);
            }
        }
    });
    function showBanner() {
        const b = document.getElementById('runningBanner');
        b.classList.toggle('d-none', !live);
        if (live) document.getElementById('runningName').textContent = live.client_name;
    }
    document.getElementById('minimizeLive').onclick = closeLive;
    document.getElementById('resumeBtn').onclick = () => panel.classList.add('show');

    document.getElementById('finishBtn').onclick = async function () {
        if (!live || !(await confirmModal({ title: 'Selesaikan sesi?', text: 'Sesi akan ditutup, paket sesi klien berkurang 1, dan laporan dikirim ke WhatsApp klien.', yes: 'Ya, selesai & kirim', icon: '💖' }))) return;
        const btn = this; btn.disabled = true; btn.textContent = 'Menyimpan...';
        const performance = [...document.querySelectorAll('#liveExercises .ex-row')].map(r => {
            // Detail per set: tiap baris set menyumbang {reps, kg} sendiri.
            const detail = [...r.querySelectorAll('.set-row')].map(sr => ({
                reps: sr.querySelector('.set-reps').value.trim() || null,
                kg: parseFloat(sr.querySelector('.set-kg').value) || 0,
            }));
            return {
                name: r.dataset.name,
                equipment: r.dataset.equipment,
                sets: detail.length || null,
                reps: detail.find(d => d.reps)?.reps ?? null,
                sets_reps: r.dataset.setsReps, // target program (cadangan)
                actual_kg: detail.reduce((m, d) => Math.max(m, d.kg), 0), // beban terberat (top set)
                sets_detail: detail,
            };
        });
        try {
            const res = await api(`/jadwal/${live.id}/selesai`, 'POST', { performance, coach_note: document.getElementById('liveNote').value });
            const cl = CLIENTS.find(x => x.id == res.schedule.client_id);
            if (cl) { cl.remaining = res.schedule.client_remaining; if (cl.booked > 0) cl.booked--; }
            clearInterval(timer); live = null; closeLive(); showBanner();
            toast(res.message, res.wa_sent ? 'success' : 'warning');
            await load();
        } catch (err) { toast(err.message, 'danger'); }
        btn.disabled = false; btn.innerHTML = 'Selesai Sesi &amp; Kirim ke WA 💖';
    };

    document.getElementById('cancelLiveBtn').onclick = async function () {
        if (!live || !(await confirmModal({ title: 'Batalkan sesi ini?', text: 'Stopwatch direset dan beban yang sudah diisi hari ini tidak akan tersimpan. Sesi klien tidak berkurang dan tidak ada pesan WA yang dikirim.', yes: 'Ya, batalkan', no: 'Lanjut latihan', icon: '⚠️', danger: true }))) return;
        try {
            await api(`/jadwal/${live.id}/batal-mulai`, 'POST');
            clearInterval(timer); live = null; closeLive(); showBanner();
            toast('Sesi dibatalkan. Jadwal kembali seperti semula 🌸');
            await load();
        } catch (err) { toast(err.message, 'danger'); }
    };

    // Lanjutkan sesi yang masih berjalan setelah refresh
    (async () => {
        await load();
        try { const r = await api('{{ route('jadwal.running') }}'); if (r) { openLive(r); closeLive(); } } catch (e) {}
    })();
})();
</script>
@endpush
