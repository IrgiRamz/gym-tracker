/*
 * Script global Gym Tracker by Neil.
 * Dimuat SEKALI di <head> (Turbo tidak menjalankan ulang script head),
 * jadi semua listener level document/window di sini tidak akan terduplikasi
 * saat berpindah halaman. Script tiap halaman (di body) dijalankan ulang oleh
 * Turbo setiap kunjungan dan hanya mengikat elemen halaman barunya.
 */
(function () {
    // ---------- Helper global ----------
    // Token CSRF dibaca saat dipakai (bukan disimpan), karena meta tag diperbarui Turbo tiap halaman.
    const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';

    window.toast = function (msg, type = 'success') {
        const slot = document.getElementById('toastSlot');
        if (!slot) return;
        const el = document.createElement('div');
        el.className = `alert alert-${type} shadow-sm rounded-4`;
        el.textContent = msg;
        slot.appendChild(el);
        setTimeout(() => el.remove(), 5000);
    };

    window.api = async function (url, method = 'GET', body = null) {
        const res = await fetch(url, {
            method,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: body ? JSON.stringify(body) : null,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            const msg = data.errors ? Object.values(data.errors).flat()[0] : (data.message || 'Terjadi kendala, coba lagi ya.');
            throw new Error(msg);
        }
        return data;
    };

    // Modal konfirmasi berbasis Promise: await confirmModal({...}) -> true/false
    window.confirmModal = function ({ title = 'Yakin?', text = '', yes = 'Ya', no = 'Kembali', icon = '💭', danger = false } = {}) {
        return new Promise(resolve => {
            const el = document.getElementById('confirmModal');
            const modal = bootstrap.Modal.getOrCreateInstance(el);
            const yesBtn = document.getElementById('confirmYes'), noBtn = document.getElementById('confirmNo');
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmText').textContent = text;
            document.getElementById('confirmIcon').textContent = icon;
            yesBtn.textContent = yes; noBtn.textContent = no;
            yesBtn.className = 'btn flex-fill py-2 ' + (danger ? 'btn-danger fw-bold rounded-4' : 'btn-pink');
            let result = false;
            const onYes = () => { result = true; modal.hide(); };
            const onNo = () => modal.hide();
            const onHidden = () => {
                yesBtn.removeEventListener('click', onYes); noBtn.removeEventListener('click', onNo);
                el.removeEventListener('hidden.bs.modal', onHidden);
                resolve(result);
            };
            yesBtn.addEventListener('click', onYes); noBtn.addEventListener('click', onNo);
            el.addEventListener('hidden.bs.modal', onHidden);
            modal.show();
        });
    };

    // Form dengan data-confirm="pesan" -> modal konfirmasi dulu (capture agar jalan sebelum Turbo)
    document.addEventListener('submit', async e => {
        const f = e.target;
        if (!f.dataset || !f.dataset.confirm || f.dataset.confirmed) return;
        e.preventDefault();
        const ok = await confirmModal({
            title: f.dataset.confirmTitle || 'Yakin?', text: f.dataset.confirm,
            yes: f.dataset.confirmYes || 'Ya, hapus', icon: '🗑️', danger: true,
        });
        if (ok) {
            f.dataset.confirmed = '1';
            f.requestSubmit(); // tetap lewat Turbo (tanpa reload penuh)
        }
    }, true);

    // ---------- Status WhatsApp (GoWA) ----------
    // Header bersifat permanen di Turbo, jadi badge tidak dirender ulang tiap pindah menu.
    // Pengecekan lewat AJAX ringan (server meng-cache hasilnya), tidak membebani navigasi.
    async function refreshWa() {
        const b = document.getElementById('waBadge');
        if (!b) return;
        try {
            const d = await api(b.dataset.url);
            b.className = 'wa-badge ' + (d.active ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger');
            b.dataset.state = d.active ? 'on' : 'off';
            document.getElementById('waText').textContent = d.active ? 'WA Aktif' : 'WA Tidak Aktif';
        } catch (e) { /* biarkan badge apa adanya */ }
    }
    setInterval(refreshWa, 60000);

    // ---------- Bottom nav: indikator aktif langsung pindah ----------
    function setActiveNav(href) {
        let path;
        try { path = new URL(href, location.origin).pathname; } catch (e) { return; }
        const key = path.startsWith('/klien') ? 'klien' : path.startsWith('/alat') ? 'alat' : (path === '/' || path.startsWith('/kalender')) ? 'kalender' : null;
        if (!key) return;
        document.querySelectorAll('.bottom-nav a').forEach(a => a.classList.toggle('active', a.dataset.nav === key));
    }
    document.addEventListener('turbo:click', e => setActiveNav(e.detail.url));   // saat diketuk (instan)
    document.addEventListener('turbo:visit', e => setActiveNav(e.detail.url));
    document.addEventListener('turbo:load', () => setActiveNav(location.href)); // koreksi (mis. tombol back)

    // ---------- Inisialisasi per halaman (initial load & tiap kunjungan Turbo) ----------
    document.addEventListener('turbo:load', () => {
        document.querySelectorAll('.auto-dismiss').forEach(el => setTimeout(() => el.remove(), 5000));
        const b = document.getElementById('waBadge');
        if (b && b.dataset.state === 'checking') refreshWa();
    });

    // ---------- Bersihkan sebelum halaman disimpan ke cache Turbo ----------
    document.addEventListener('turbo:before-cache', () => {
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.querySelectorAll('.modal.show').forEach(el => {
            el.classList.remove('show'); el.style.display = 'none'; el.setAttribute('aria-hidden', 'true');
            el.removeAttribute('aria-modal'); el.removeAttribute('role');
        });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        document.querySelectorAll('.toast-wrap .alert').forEach(el => el.remove());
    });

    // ---------- Service Worker (PWA) ----------
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
})();
