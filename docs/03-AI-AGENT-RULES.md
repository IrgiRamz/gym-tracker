# 03 — Aturan Wajib untuk AI Agent / Developer Berikutnya

> Baca `00`, `01`, `02` lebih dulu. Aturan di bawah lahir dari keputusan pemilik proyek dan masalah nyata yang sudah ditemui. **Lebih baik bertanya daripada melanggar.**

## A. Aturan emas

1. **Perbarui `docs/` di setiap perubahan** fitur, route, controller, service, kolom/migration, aturan bisnis, atau `.env`. Pekerjaan belum selesai sebelum dokumen yang terkait ikut diperbarui. Cantumkan tanggal "Terakhir diperbarui" di `00-PROJECT-OVERVIEW.md`.
2. **Kode adalah sumber kebenaran.** Jika dokumen tidak cocok dengan kode, perbaiki dokumen (atau tanyakan ke pemilik jika ternyata kode yang keliru).
3. **Jangan merombak yang sudah jalan.** Ubah seminimal yang diminta; jangan ganti alur transaksi (selesai sesi, pengurangan paket, generate program) tanpa diminta.
4. **Jangan mengeksekusi aksi yang ditolak pemilik** (mis. memanggil API eksternal untuk "tes"). Uji dengan `Http::fake` atau data seed.
5. **Privasi & kredensial**: jangan menulis email/password asli, API key, atau token ke dokumentasi, kode, atau pesan commit. Akun PT ada di `DatabaseSeeder` (sudah diubah pemilik); jangan membocorkannya.

## B. Kompatibilitas Hotwire Turbo (SPA tanpa reload) — WAJIB

Aplikasi berjalan sebagai SPA lewat **Turbo Drive**. Pelanggaran paling sering menyebabkan listener ganda, stopwatch error, atau halaman "rusak" setelah pindah menu.

1. **Urutan script di `<head>` jangan diubah**: Bootstrap JS → `public/js/app.js` → Turbo. `app.js` harus sebelum Turbo agar listener `submit` (konfirmasi) terpasang lebih dulu.
2. **Script global hanya di `public/js/app.js`** (dieksekusi sekali). Semua listener level `document`/`window`, `setInterval` global, dan helper global (`api`, `toast`, `confirmModal`) ada di sana. **Jangan** menaruh `document.addEventListener(...)` / `window.addEventListener(...)` / `setInterval` di script halaman tanpa pembersihan — akan menumpuk setiap kunjungan.
3. **Script halaman** (`@push('scripts')`) harus berupa **IIFE** yang hanya mengikat elemen halaman itu sendiri. Turbo menjalankannya ulang tiap kunjungan, elemen lama dibuang bersama body sehingga tidak terduplikasi. Inisialisasi yang perlu jalan di setiap halaman/kunjungan dan bersifat global → daftarkan **satu kali** di `app.js` pada event **`turbo:load`**.
4. Bila halaman memakai timer / listener global, bersihkan dengan `document.addEventListener('turbo:before-render', cleanup, { once: true })` (contoh: stopwatch di `calendar.blade.php`). Guard elemen yang bisa hilang (`if (!document.getElementById(...)) return;`) di callback async (`fetch().then`) karena halaman bisa sudah berpindah.
5. **Header & bottom nav bersifat `data-turbo-permanent`** (`#appHeader`, `#bottomNav`). Jangan hapus atribut/ID-nya. Menu aktif diatur JS (`setActiveNav` di `app.js`) — jika menambah menu baru, tambahkan `data-nav="…"` dan perluas pemetaan path di `setActiveNav`.
6. **Token CSRF dibaca saat dipakai** (`document.querySelector('meta[name=csrf-token]')`), jangan disimpan di variabel global (token berubah setelah login/Turbo render). Selalu panggil API lewat `api()`.
7. **Form non-GET**: pola Laravel `redirect()`/`back()` (302) bekerja dengan Turbo. Form `PATCH/DELETE` memakai `@method` (POST + `_method`). Form yang butuh reload penuh (mis. login) pakai `data-turbo="false"`; halaman yang harus selalu reload penuh pakai `<meta name="turbo-visit-control" content="reload">`.
8. **Konfirmasi**: jangan pakai `confirm()` bawaan browser. Pakai `confirmModal({...})` (Promise) atau atribut `data-confirm="pesan"` (+ `data-confirm-title`, `data-confirm-yes`) pada `<form>`.
9. Modal Bootstrap: sebelum snapshot cache Turbo, `app.js` (`turbo:before-cache`) menutup modal/backdrop dan membuang flash. Jika menambah elemen sementara serupa (overlay, toast), bersihkan di handler itu juga.
10. Layout memakai `<meta name="turbo-cache-control" content="no-preview">`. Jangan mengandalkan preview cache untuk konten yang dirender JS.
11. Halaman publik `/progress/{token}` dan `auth/login` **bukan** bagian shell (dokumen mandiri tanpa `layouts.app`). Tautan ke sana dari dalam app: `target="_blank"` + `data-turbo="false"`.

## C. Kinerja — jaga ringan & cepat

1. **Jangan ada request HTTP eksternal saat render halaman atau saat memuat kalender.** Fitur hari libur nasional (`HolidayService`) pernah dibuat lalu **sengaja dibatalkan** karena memberatkan kalender. Jangan menambahkannya lagi tanpa persetujuan eksplisit.
2. Badge WA: render server hanya membaca cache (`GowaWhatsappService::cachedStatus()`); pengecekan sebenarnya lewat AJAX `GET /wa-status` (cache 120 dtk). **Jangan** memanggil `checkStatus()` di View composer / layout.
3. Panggilan lambat (AI Gemini, ≤ 40 dtk) dilakukan **di luar** `DB::transaction`.
4. Data besar (mis. history klien) dimuat lazy lewat endpoint JSON saat modal dibuka, bukan ditanam di halaman daftar.
5. Aset lokal diberi cache-busting `?v={{ filemtime(public_path('…')) }}` (lihat layout). Pertahankan pola ini untuk CSS/JS baru. Service worker meng-cache aset statis; **jangan** menambahkan halaman dinamis/JSON ke cache SW.

## D. Estetika & konten

1. **Pastel pink**: pakai variabel CSS (`--pink #ec4899`, `--surface #fdf2f8`, `--border #fbcfe8`, …) di `public/css/app.css`; font `Plus Jakarta Sans`; container maks **540px**; mobile-first.
2. **Seluruh teks UI/validasi/pesan dalam Bahasa Indonesia yang ramah** (sapaan lembut, emoji secukupnya). Pesan validasi baru wajib berbahasa Indonesia.
3. Warna navy hanya untuk **kartu progres publik klien Pria** (`theme-navy`); selebihnya pink. Pesan WA/UI admin tetap nuansa pink.
4. Gunakan komponen yang sudah ada: `.chip`/`.chips` + `data-chip-group`, `.soft-card`, `.client-card`, `.protein-box`, `.info-warn`, `.btn-pink`, `.btn-soft`, modal Bootstrap.
5. Pertahankan transisi fade 150 ms (`main.page`) dan dukungan `prefers-reduced-motion`.

## E. Aturan bisnis yang tidak boleh rusak

1. **Rentang protein** hanya dihitung dari `Client::PROTEIN_MATRIX`/`ACTIVITIES` (server) dan matriks yang sama dikirim ke JS. Jangan menduplikasi angka. Bila matriks/tier berubah: perbarui model, uji ulang PHP vs JS, dan `01`/`02`. `daily_protein_target` (kolom lama) harus tetap = `daily_protein_max`.
2. **`body_focus` selalu array** (`Client::normalizeFocus`); `Seluruh Tubuh` eksklusif. Data lama berupa string harus tetap ditangani (`AiWorkoutService::focusList`).
3. **Selesai sesi**: dalam satu transaksi → `completed`, durasi, performa, catatan, `share_token`, `remaining_sessions - 1` (hanya bila >0). WA dikirim **setelah** data tersimpan; kegagalan GoWA tidak boleh membatalkan/menyebabkan crash.
4. **Generate ulang program**: nonaktifkan (`is_active=false`), jangan hapus program lama; `cycle_number + 1`; jangan sentuh jadwal `completed`/berjalan (riwayat harus utuh).
5. **`actual_performance_json`**: tulis dengan skema baru (`name, equipment, sets, reps, sets_reps, actual_kg`) dan **baca lewat `Schedule::normalizePerformance()`/`normalizedPerformance()`** untuk WA, History, dan kartu progres, agar data format lama tetap tampil benar. Jangan membaca `sets_reps`/`actual_kg` mentah di view.
6. **Jumlah gerakan per sesi 4–5** (`AiWorkoutService::MIN_EXERCISES/MAX_EXERCISES`); AI hanya boleh memakai alat `is_available = true`.
7. Link kartu progres di WA berasal dari `url('/progress/{token}')` → `APP_URL` harus publik di produksi. URL berada di **baris sendiri**.
8. Sesi login **1 tahun**: `SESSION_LIFETIME=525600` dan `Auth::attempt(..., true)`. Jangan memperpendek.
9. Semua route aplikasi (kecuali `/login` & `/progress/{token}`) wajib middleware `auth`.

## F. Konvensi kode

- **Laravel 12 / PHP 8.2.** Controller tipis; logika layanan eksternal di `app/Services`. Konfigurasi key lewat `config/services.php`, bukan `env()` di kode.
- **Migration**: tambah file baru (prefix `2026_01_01_0000NN_…`); jangan mengubah migration lama yang sudah dijalankan. Sertakan backfill data lama bila mengubah kolom, dan `down()` yang valid (ingat: tulis nilai ke kolom `json` harus JSON valid — ubah tipe dulu baru update saat rollback).
- Validasi lewat `$request->validate()` dengan pesan Indonesia; bagikan aturan antara store & update (lihat `ClientController::validateClient`).
- Endpoint JSON dipanggil lewat `api()` (header Accept/CSRF otomatis; error Laravel `message`/`errors` diubah jadi `Error.message`).
- Escape output JS manual (`esc()`) saat membangun HTML dari data; atribut data JSON di Blade pakai `{{ json_encode(...) }}` (otomatis di-escape).
- Tidak ada build step. Jangan menambah Vite/npm tanpa persetujuan.

## G. Cara menguji (praktik yang berhasil di proyek ini)

- Lingkungan: Windows + Laragon, MySQL (`gym_tracker`), PHP 8.2. Banyak `Warning: Module "intl" is already loaded` di output CLI — abaikan (noise).
- Reset data contoh: `php artisan migrate:fresh --seed`. **Selalu reset setelah uji yang mengubah data**, lalu laporkan.
- Menguji endpoint tanpa login manual: di `php artisan tinker --execute="…"` lakukan `Auth::loginUsingId(User::first()->id);` lalu `app(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create('/klien'))`. Atau panggil controller langsung dengan `Request::create(...)`.
- **Jangan** memakai `php artisan tinker file.php` (menunggu input interaktif dan menggantung); pakai `--execute`. Jangan jalankan `python` interaktif.
- Server uji: `php artisan serve --port=8099` (hentikan setelah selesai). Tes curl butuh login + token CSRF (sesi database; `migrate:fresh` menghapus sesi).
- Uji JS inline Blade: ekstrak `<script>` dari HTML yang dirender lalu `node --check`.
- Layanan eksternal (Gemini, GoWA): gunakan `Http::fake([...])`; ingat stub pertama yang cocok yang dipakai — jalankan skenario gagal/sukses di proses terpisah.
- **PERINGATAN — GoWA bisa aktif sungguhan.** Jika `GOWA_BASE_URL` terisi di `.env`, memanggil `ScheduleController@finish` (atau `GowaWhatsappService::sendMessage`) dari tinker/test **mengirim WhatsApp nyata** ke nomor klien. Selalu `Http::fake()` untuk host GoWA (atau kosongkan `GOWA_BASE_URL` untuk proses uji) sebelum menguji alur "Selesai Sesi". Nomor klien seed (`0812345678xx`) bukan nomor asli.
- Tampilan/perilaku Turbo, service worker, dan PWA **tidak bisa dibuktikan lewat CLI** — nyatakan jujur bila belum diuji di browser.

## H. Hal yang perlu diketahui / utang teknis

- Tombol Logout tidak ada di UI (route `/logout` ada). Status `cancelled` ada di skema tetapi belum dipakai fitur apa pun.
- Tidak ada test otomatis (PHPUnit) untuk fitur-fitur di atas.
- PWA/service worker hanya jalan di HTTPS atau `localhost`; di `*.test` via HTTP hanya navigasi Turbo yang aktif.
- URL Turbo CDN (`@hotwired/turbo@8.0.4/dist/turbo.es2017-umd.js`) belum diverifikasi dari CDN langsung; bila gagal dimuat, aplikasi tetap jalan sebagai multi-page biasa.
- Endpoint GoWA yang dipakai (`/app/devices`, `/send/message`, header `X-Device-Id`) ditulis dari pengetahuan umum GoWA dan belum dicocokkan dengan dokumentasi/instans pemilik; sesuaikan bila instans berbeda.
- Keaktifan klien (`activity_level`) bisa diubah lewat form Edit; aturan pergeseran tier (−1/0/+1) adalah keputusan implementasi yang dapat diubah di `Client::ACTIVITIES`.

## I. Checklist sebelum menyelesaikan tugas

- [ ] Perubahan tidak melanggar aturan Turbo (B) dan kinerja (C).
- [ ] Migration baru (bila ada) sudah dijalankan; `down()` valid; backfill data lama.
- [ ] Teks UI/validasi Bahasa Indonesia; gaya pastel pink konsisten.
- [ ] Uji lewat tinker/Kernel/`Http::fake`; data contoh direset.
- [ ] **`docs/` diperbarui** (route, kolom, aturan bisnis, `.env`, tanggal).
- [ ] Laporkan jujur apa yang sudah dan belum diuji.
