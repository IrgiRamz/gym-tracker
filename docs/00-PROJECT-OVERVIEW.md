# 00 — Project Overview: Gym Tracker by Neil

> Terakhir diperbarui: 2026-11-20 (live session: set/repetisi/beban aktual). Folder `docs/` adalah **cetak biru proyek**. Baca semua file di folder ini (00 → 03) sebelum mengubah kode.
> Dokumen ini ditulis dari kode aktual. Jika kode dan dokumen berbeda, **kode yang benar** — lalu perbaiki dokumennya.

## Daftar dokumen

| File | Isi |
|---|---|
| `00-PROJECT-OVERVIEW.md` | Konsep, pengguna, stack, struktur folder, desain UI/UX, arsitektur PWA + Turbo |
| `01-DATABASE-AND-MODELS.md` | Semua tabel, kolom, relasi model, aturan khusus (JSON, protein, share_token, session 1 tahun) |
| `02-FEATURES-AND-BUSINESS-LOGIC.md` | Alur tiap fitur, route, controller, service, format pesan WA, kartu progres |
| `03-AI-AGENT-RULES.md` | Aturan wajib bagi AI agent / developer berikutnya |

## 1. Apa ini?

**Gym Tracker by Neil** adalah aplikasi web manajemen **Personal Trainer (PT)**. Satu pengguna utama: PT bernama **Neil** (Coach Neil) yang memakai **smartphone di lantai gym** untuk:

1. Mengelola **klien** (data diri, tujuan, fokus tubuh, paket sesi, berat badan, target protein).
2. Membuat **program latihan** per klien lewat **AI (Gemini)** — dengan template cadangan bila AI tidak tersedia.
3. Menjadwalkan sesi di **kalender**, menjalankan **sesi langsung (live session)** dengan stopwatch dan input beban aktual.
4. Mengirim **laporan sesi otomatis via WhatsApp** (GoWA) berisi ringkasan, pencapaian, saran protein, dan link **Kartu Progres IG Story**.
5. Mengelola **master alat gym** yang tersedia (dipakai AI agar hanya memberi gerakan dengan alat yang ada).

Hanya PT yang login. **Klien tidak punya akun**; klien hanya menerima pesan WhatsApp dan membuka halaman publik kartu progres lewat link bertoken.

## 2. Alur pengguna (PT)

```
Login (sekali, sesi bertahan 1 tahun)
 └─ Bottom nav 3 menu:
     ├─ Kalender  → pilih tanggal → + Tambah Jadwal → [Mulai] → Live Session → [Selesai Sesi & Kirim ke WA]
     ├─ Klien     → Klien Baru (wizard 3 langkah + AI) / Edit / Lihat Program / History / BB / Paket
     └─ Alat Gym  → tambah, aktif/nonaktifkan, hapus alat
```

Alur inti harian: **Kalender → Mulai → latih (ubah beban aktual, tulis catatan) → Selesai** → sisa paket berkurang 1, laporan WA terkirim, kartu progres tersedia di `/progress/{token}`.

## 3. Tech stack & lingkungan

| Bagian | Pilihan |
|---|---|
| Backend | **Laravel 12** (PHP 8.2), Blade |
| Database | **MySQL** (`DB_DATABASE=gym_tracker`, user `root`) |
| Frontend | **Bootstrap 5.3.3** + Bootstrap Icons 1.11.3 (CDN), **Vanilla JS** (tanpa build step / Vite) |
| SPA | **Hotwire Turbo 8.0.4** (CDN) — navigasi tanpa reload penuh |
| PWA | `manifest.json`, `sw.js`, ikon pastel pink |
| AI | **Gemini API** via HTTP Client Laravel (`AiWorkoutService`) |
| WhatsApp | **GoWA REST API** (`GowaWhatsappService`) |
| Session/Cache/Queue | driver `database` (`SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`) |
| Locale/Zona waktu | `APP_LOCALE=id`, `APP_TIMEZONE=Asia/Jakarta` (config/app.php membaca `APP_TIMEZONE`) |
| Dev lokal | Laragon (Windows), MySQL 8.0.x |

Tidak ada Vite/npm. Seluruh aset: `public/css/app.css`, `public/js/app.js`, plus CDN.

### Variabel `.env` penting

```
APP_NAME="Gym Tracker by Neil"   APP_LOCALE=id   APP_TIMEZONE=Asia/Jakarta
APP_URL=http://localhost         # WAJIB diganti ke URL publik; dipakai untuk link kartu progres di WhatsApp
DB_CONNECTION=mysql  DB_DATABASE=gym_tracker  DB_USERNAME=root  DB_PASSWORD=
SESSION_DRIVER=database  SESSION_LIFETIME=525600      # 1 tahun
GEMINI_API_KEY=  GEMINI_MODEL=gemini-2.0-flash
GOWA_BASE_URL=  GOWA_USERNAME=  GOWA_PASSWORD=  GOWA_DEVICE_ID=
```

Semua key layanan dibaca lewat `config/services.php` (`services.gemini.*`, `services.gowa.*`). Jangan pakai `env()` langsung di luar config.

## 4. Struktur folder yang relevan

```
app/
  Http/Controllers/
    AuthController.php            login/logout
    DashboardController.php       halaman kalender + endpoint /wa-status
    ClientController.php          CRUD klien, edit+regenerate AI, berat badan, paket sesi, history
    ScheduleController.php        jadwal + live session (mulai/batal/selesai)
    EquipmentController.php       master alat gym
    PublicProgressController.php  kartu progres publik (tanpa auth)
  Models/  User, Equipment, Client, WorkoutProgram, Schedule, WeightLog
  Services/
    AiWorkoutService.php          Gemini + template cadangan
    GowaWhatsappService.php       status GoWA, kirim pesan, susun laporan
  Providers/AppServiceProvider.php   View composer: kirim $waState ke layouts.app
database/migrations/  (lihat 01-DATABASE-AND-MODELS.md)
database/seeders/DatabaseSeeder.php  akun PT, 6 alat, 3 klien contoh + program + jadwal
resources/views/
  layouts/app.blade.php   layout utama (head PWA+Turbo, header & bottom nav permanen, modal konfirmasi)
  calendar.blade.php  clients.blade.php  equipments.blade.php
  auth/login.blade.php    (dokumen mandiri, tanpa layout)
  public/progress.blade.php  (dokumen mandiri, tanpa layout)
public/
  css/app.css   js/app.js   sw.js   manifest.json   offline.html   images/ (ikon PNG + SVG)
routes/web.php
docs/   ← folder ini
```

## 5. Pedoman desain UI/UX

- **Mobile-first**. `.app-shell` dibatasi **max-width 540px**, di tengah layar saat dibuka di desktop.
- **Palet** (CSS variables di `public/css/app.css`):
  - Utama `#ec4899` (`--pink`), gelap `#db2777` (`--pink-dark`)
  - Surface lembut `#fdf2f8` (`--surface`), border `#fbcfe8` (`--border`)
  - Teks `#3b1d2f` (`--ink`), muted `#9d6b86`
- **Font**: `Plus Jakarta Sans` (Google Fonts).
- **Bahasa**: seluruh UI, label, validasi, pesan, dan notifikasi **Bahasa Indonesia yang ramah dan suportif** (target klien wanita; sapaan "kak", emoji lembut 💖🌸✨).
- **Komponen khas**:
  - *Choice chips* (`.chip`, `.chips`, atribut `data-chip-group`) untuk pilihan gender, tujuan, frekuensi, keaktifan; multi-select untuk fokus tubuh.
  - **Floating bottom navigation** 3 menu: Kalender, Klien, Alat Gym.
  - Header kiri: ikon + "Gym Tracker by Neil". Header kanan: **badge status WhatsApp** (`WA Aktif` hijau / `WA Tidak Aktif` merah / `Cek WA...` abu saat belum dicek).
  - **Modal konfirmasi Bootstrap** (bukan `confirm()` bawaan browser) lewat `confirmModal()` atau atribut `data-confirm` pada `<form>`.
  - Panel **live session** berupa bottom-sheet fullscreen dengan stopwatch.
  - Kotak peringatan pastel kuning (`.info-warn`) di form edit klien.
- **Pengecualian warna**: kartu progres publik untuk klien **Pria** memakai tema **navy** (class `theme-navy`) agar tidak terlalu feminin; klien Wanita tetap pink.
- Transisi halus: fade 150 ms pada `main.page` setiap pindah halaman (dimatikan bila `prefers-reduced-motion`).

## 6. Arsitektur PWA + Turbo (ringkas)

Detail aturan ada di `03-AI-AGENT-RULES.md`. Gambaran:

- `layouts/app.blade.php` memuat di `<head>` **secara berurutan**: Bootstrap JS → `public/js/app.js` → Turbo. Script head hanya dieksekusi **sekali**; karena itu helper global dan listener level `document` hidup di `app.js`.
- Script khusus halaman (`@push('scripts')`, berupa IIFE di akhir `<body>`) **dijalankan ulang Turbo di setiap kunjungan** dan hanya mengikat elemen halaman baru.
- `<header id="appHeader">` dan `<nav id="bottomNav">` ditandai `data-turbo-permanent` → tidak dirender ulang, badge WA tidak berkedip, indikator menu aktif berpindah halus (diatur `app.js` lewat event `turbo:click`/`turbo:visit`/`turbo:load`).
- Halaman login memakai `<meta name="turbo-visit-control" content="reload">` dan form `data-turbo="false"` → selalu load penuh.
- Service worker: aset statis *stale-while-revalidate*; halaman dinamis, JSON, dan semua non-GET **tidak pernah di-cache**; offline → `offline.html`.
- PWA install & service worker butuh **HTTPS** (atau `localhost`).
