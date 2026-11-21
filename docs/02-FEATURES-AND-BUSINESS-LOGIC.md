# 02 — Fitur & Logika Bisnis

> Semua rincian di bawah berasal dari kode aktual. Nama route = nama di `routes/web.php`.

## Peta route

| Method & URL | Controller@method | Nama | Auth | Keterangan |
|---|---|---|---|---|
| GET `/progress/{token}` | PublicProgressController@show | progress.show | **publik** | kartu progres IG Story |
| GET `/login` · POST `/login` | AuthController@showLogin / login | login, login.attempt | guest | |
| POST `/logout` | AuthController@logout | logout | auth | (belum ada tombolnya di UI) |
| GET `/` dan `/kalender` | DashboardController@index | kalender | auth | halaman kalender |
| GET `/wa-status` | DashboardController@waStatus | wa.status | auth | JSON `{active: bool}` untuk badge |
| GET `/klien` | ClientController@index | klien.index | auth | |
| POST `/klien` | ClientController@store | klien.store | auth | tambah + generate AI |
| PATCH `/klien/{client}` | ClientController@update | klien.update | auth | edit + opsi generate ulang |
| DELETE `/klien/{client}` | ClientController@destroy | klien.destroy | auth | |
| PATCH `/klien/{client}/berat` | ClientController@updateWeight | klien.weight | auth | |
| POST `/klien/{client}/sesi` | ClientController@addSessions | klien.sessions | auth | tambah paket |
| GET `/klien/{client}/riwayat` | ClientController@history | klien.history | auth | JSON history |
| GET `/alat` · POST `/alat` | EquipmentController@index / store | alat.index, alat.store | auth | |
| PATCH `/alat/{equipment}/toggle` · DELETE `/alat/{equipment}` | …@toggle / destroy | alat.toggle, alat.destroy | auth | |
| PATCH `/alat/{equipment}/foto` · DELETE `/alat/{equipment}/foto` | …@updatePhoto / destroyPhoto | alat.foto, alat.foto.hapus | auth | tambah/ganti & hapus foto alat (disk `public`) |
| DELETE `/alat-reset` | EquipmentController@reset | alat.reset | auth | hapus SEMUA alat (reset dari 0, pakai modal konfirmasi) |
| GET `/jadwal?month=YYYY-MM` | ScheduleController@index | jadwal.index | auth | JSON jadwal sebulan |
| GET `/jadwal/berjalan` | …@running | jadwal.running | auth | sesi yang sedang berjalan |
| POST `/jadwal` | …@store | jadwal.store | auth | |
| DELETE `/jadwal/{schedule}` | …@destroy | jadwal.destroy | auth | tidak bisa jika `completed` |
| POST `/jadwal/{schedule}/mulai` | …@start | jadwal.start | auth | |
| POST `/jadwal/{schedule}/batal-mulai` | …@cancelStart | jadwal.cancelStart | auth | |
| POST `/jadwal/{schedule}/selesai` | …@finish | jadwal.finish | auth | |

Halaman Blade (form biasa) memakai redirect + flash `success`; endpoint JSON (`/jadwal*`, `/klien/{id}/riwayat`, `/wa-status`) dipanggil lewat `api()` di `public/js/app.js`.

## 1. Autentikasi

- `/login` (`resources/views/auth/login.blade.php`): satu kolom **"Email atau Nama"** (`name="login"`) + Password, tombol "Masuk ✨", desain pastel pink; pesan error Bahasa Indonesia.
- **Login dengan email ATAU nama** (`AuthController@login`): bila isian berformat email → `Auth::attempt(['email'=>…])`; selain itu dicari akun dengan `users.name` yang sama (MySQL tidak membedakan huruf besar/kecil) dan dicoba lewat emailnya. Nama tidak unik, jadi bila ada beberapa akun bernama sama, semuanya dicoba satu per satu sampai password cocok. Gagal → "Email/nama atau password belum cocok. Coba cek lagi ya 💕" (error key `login`).
- `Auth::attempt(..., true)` → **remember = true default**; sesi 1 tahun (`SESSION_LIFETIME=525600`). Berhasil → `redirect()->intended('/')`.
- Sudah login lalu membuka `/login` → middleware `guest` mengarahkan ke `/`. Belum login mengakses halaman lain → redirect ke `/login`.
- Halaman login **selalu load penuh** (bukan Turbo): `<meta name="turbo-visit-control" content="reload">` + `data-turbo="false"` pada form, supaya token CSRF dan sesi baru terbaca bersih.

## 2. Master Alat Gym (`/alat`, `EquipmentController`)

- Tambah alat (nama + kategori via chips: Beban Bebas, Mesin Latihan, Kardio & Matras, Tanpa Alat); **toggle** ketersediaan (`is_available`) lewat **AJAX** (`api()` PATCH, controller membalas JSON bila `expectsJson`) sehingga halaman tidak reload dan posisi scroll tetap; tanpa JS/permintaan non-JSON controller tetap `back()` seperti biasa; hapus (modal konfirmasi lewat `data-confirm`). Ada juga tombol **Reset Semua Alat** di bagian bawah daftar (hanya tampil bila ada alat): form DELETE ke `alat.reset` dengan modal konfirmasi `data-confirm` bergaya bahaya, menghapus **seluruh** alat sekaligus agar daftar bisa diinput ulang dari 0; selama daftar kosong, AI hanya memakai gerakan "Tanpa Alat".
- **Foto alat (opsional)**: form tambah alat punya input file foto; tiap alat bisa tambah/ganti foto (tombol kamera, langsung unggah saat file dipilih) dan hapus foto (link "Hapus foto" di bawah status, pakai modal konfirmasi). Validasi: gambar JPG/PNG/WebP maks 4 MB, disimpan di disk `public` folder `equipments` (kolom `image_path`). Root disk `public` sengaja diarahkan ke folder asli `public/uploads` dengan awalan URL `/uploads` (lihat `config/filesystems.php`): awalan `/storage/*` diblokir 404 oleh snippet keamanan Caddyfile bersama di VPS, dan FrankenPHP juga tidak melayani file di balik symlink folder (`storage:link`). Baris alat menampilkan thumbnail 52px; klik thumbnail membuka foto penuh di modal `#equipmentImageModal`. Foto dipakai untuk ikon mata di modal Lihat Program dan di live session (lihat §3e dan §5); pemetaan nama alat → URL foto dibangun `Equipment::imageMap()` dan dikirim ke kedua halaman sebagai `EQUIPMENT_IMAGES`.
- **Integrasi AI**: `AiWorkoutService::generate()` mengambil `Equipment::where('is_available', true)->pluck('name')` dan memasukkannya ke *system prompt* Gemini ("WAJIB hanya memakai alat dari daftar berikut atau 'Tanpa Alat'"). Template cadangan juga hanya memakai alat yang tersedia (pencocokan kata kunci nama alat: `find()` memakai alat **pertama** yang namanya memuat kata kunci, bisa daftar kata kunci berurutan). Gunakan kata kunci yang spesifik (mis. `'lat pulldown'`, bukan `'lat'` yang ikut cocok dengan "Barbell & **Plate**"). Gerakan bodyweight memakai `kw = null` (alat "Tanpa Alat"); alat yang tidak ada → gerakan pengganti bodyweight atau dilewati.

## 3. Manajemen Klien (`/klien`, `ClientController`, `clients.blade.php`)

Kartu klien menampilkan: avatar inisial, nama, nomor WA, tag (tujuan, tiap fokus tubuh, frekuensi), statistik (**Sisa sesi**, **Berat** + selisih dari log pertama, **Protein/hari** `min–max g`), catatan, dan tombol: **Edit**, **Hapus**, **Lihat Program**, **History**, **BB**, **Paket**.

### 3a. Tambah klien (wizard 3 langkah, modal `#wizardModal`)
1. Data diri: nama, nomor WA, gender (chips), berat, tinggi (opsional). Tidak ada lingkar tubuh (menjaga kenyamanan klien).
2. Tujuan utama (chips), **fokus bagian tubuh (multi-select chips)**, frekuensi 1x/2x/3x, **tingkat keaktifan di luar sesi PT**, paket sesi, catatan.
3. Ringkasan: **rentang protein real-time** (`75g – 90g / hari`, "Batas minimal aman | Target optimal", panduan porsi ayam/telur/tempe untuk minimal & optimal) + info bahwa AI akan membuat program.

`store()`: validasi (pesan Indonesia) → `normalizeFocus()` → `calcProteinRange()` → `AiWorkoutService::generate()` → transaksi: buat klien, 1 `weight_logs`, dan program siklus 1 (`cycle_number = 1`, `is_active = true`) per sesi. Flash: "…dibuat dengan bantuan AI" atau "…dengan template gerakan".

### 3b. Kalkulator protein (logika)
Lihat `01-DATABASE-AND-MODELS.md` → "Rentang protein". Di form (klien baru **dan** edit) fungsi `renderProtein()` di `clients.blade.php` menghitung ulang saat berat, gender, tujuan, frekuensi, atau keaktifan berubah. Hasilnya identik dengan server (sudah diuji 864 kombinasi).

### 3c. Edit klien + generate ulang (modal `#editModal`, `ClientController@update`)
- Form terisi otomatis (data diambil dari atribut `data-client` pada tombol Edit). Semua field tersedia: nama, WA, gender, berat, tinggi, sisa paket, catatan, tujuan, fokus (multi), frekuensi, keaktifan; rentang protein dihitung real-time.
- Kotak peringatan kuning (`.info-warn`): "⚠️ Info: Jika kamu mengedit … program gerakan AI klien akan dibuat ulang…".
- Dua tombol submit (`name="regenerate"`):
  - **Simpan Perubahan & Generate Gerakan Baru ✨** (`regenerate=1`): panggil AI (di luar transaksi), lalu dalam transaksi: program aktif lama → `is_active=false`, buat program baru dengan `cycle_number = max(cycle_number)+1`, dan **jadwal mendatang yang belum dimulai** (`status=scheduled`, `started_at null`, tanggal ≥ hari ini) dialihkan ke program baru secara bergilir (Sesi A, B, C…). Sesi `completed`/berjalan **tidak disentuh** → riwayat utuh.
  - **Simpan Data Saja** (`regenerate=0`): hanya update data; program tidak berubah.
- Jika berat berubah → ikut dicatat di `weight_logs`. Rentang protein dihitung ulang.
- Validasi gagal pada form edit: redirect balik dengan error di toast (input `_form=edit` mencegah modal wizard terbuka otomatis).

### 3d. Berat badan, paket, hapus
- **BB** (`updateWeight`): input berat baru, tampil riwayat dengan selisih; `weight_logs` bertambah; protein dihitung ulang.
- **Paket** (`addSessions`): `increment('remaining_sessions', n)`.
- **Hapus**: `data-confirm` modal; cascade ke program/jadwal/log.

### 3e. Lihat Program
Modal membaca `activePrograms` (dari atribut `data-client`) dan menampilkan tiap sesi dengan gerakan, set×rep, target kg, alat, tips. Bila alat suatu gerakan punya foto (`EQUIPMENT_IMAGES[equipment]` ada), di kanan baris gerakan tampil tombol ikon mata (`bi-eye`) yang membuka foto alat di modal `#equipmentImageModal`; tanpa foto, tombol tidak dirender.

### 3f. History sesi (`#historyModal`, `ClientController@history`)
Dimuat saat modal dibuka via `GET /klien/{id}/riwayat` (tidak membebani halaman daftar). Respons JSON: `name, total, remaining, first, last, sessions[]`.
- Ringkasan atas: **Sesi selesai**, **Sisa paket**, tanggal latihan pertama → terakhir.
- Hanya `status = completed`, urut **terbaru → terlama** (kalkulasi selisih beban dilakukan urut lama → baru).
- Tiap sesi: tanggal (`translatedFormat('l, d F Y')`), jam mulai, durasi, label program, daftar gerakan dengan nama, alat, **set × repetisi aktual** (`sets_label`, mis. "4 set x 10 reps") + beban riil (kg), **selisih beban vs sesi sebelumnya** untuk gerakan sama (▲ hijau / ▼ oranye), `coach_note`, dan tombol **"Lihat Kartu IG Story 📸"** (jika ada `share_token`) → `/progress/{token}` di tab baru (`target="_blank"`, `data-turbo="false"`).
- Kosong → "Belum ada riwayat sesi selesai untuk klien ini. Yuk mulai sesi pertamanya dari Kalender! 🌸".

## 4. Generator program AI (`AiWorkoutService`)

- `generate(array $client): array{programs, source}` — `source` = `'ai'` atau `'template'`.
- **Gemini**: aktif bila `GEMINI_API_KEY` terisi. `POST https://generativelanguage.googleapis.com/v1beta/models/{GEMINI_MODEL}:generateContent` dengan header `x-goog-api-key`, `systemInstruction` (aturan + daftar alat), `responseMimeType: application/json`, timeout 40 dtk. Prompt memuat gender, berat, tinggi, tujuan, **seluruh array fokus tubuh** (AI diminta membagi area secara seimbang antar sesi dan mencantumkannya di `day_label`), frekuensi, catatan.
- Jumlah sesi = `pt_frequency_per_week` (2x → Sesi A & B). **Gerakan per sesi: minimal 4, maksimal 5** (`MIN_EXERCISES`/`MAX_EXERCISES`; hasil AI dipotong ke 5).
- **Fallback template** (`fallback()`): dipakai bila key kosong / API gagal / respons tak valid. Memakai pustaka gerakan per area (Bokong, Kaki & Paha, Dada, Punggung, Lengan & Bahu, Perut & Pinggang, Seluruh Tubuh), membagi area fokus bergiliran ke tiap sesi, 1 pemanasan + 4 gerakan utama, mengganti gerakan dengan versi bodyweight bila alat tidak ada, dan melengkapi sampai ≥4 gerakan. Beban awal wanita = 0,6× pengali.

## 5. Kalender & live session (`/`, `calendar.blade.php`, `ScheduleController`)

**Kalender**: grid bulan penuh (minggu mulai Senin), tombol `<` `>`; titik **pink** = ada jadwal belum selesai, titik **hijau** = ada yang `completed`. Ketuk tanggal → agenda + **+ Tambah Jadwal**. Data lewat `GET /jadwal?month=YYYY-MM` (satu request ringan; **tanpa request eksternal** — fitur hari libur sengaja dibatalkan karena memberatkan).

**Tambah jadwal**: pilih klien, program (default otomatis), tanggal, jam. **Aturan sisa sesi** (`ScheduleController@store`, dalam transaksi dengan `lockForUpdate` pada klien):
1. `remaining_sessions <= 0` → ditolak (422): *"Sisa sesi klien ini sudah habis (0). Silakan perpanjang paket sesi di menu Klien terlebih dahulu."*
2. Jumlah jadwal klien berstatus `scheduled` (sudah dibooking, belum selesai) `>= remaining_sessions` → ditolak: *"Sisa sesi klien ini (N) sudah terpakai semua oleh jadwal yang sudah dibuat. Selesaikan atau hapus jadwal lama, atau perpanjang paket sesi di menu Klien."* (karena tiap sesi selesai mengurangi 1 sisa, booking tidak boleh melebihi sisa; jadwal lama yang tidak pernah diselesaikan ikut terhitung — hapus bila tidak jadi).

UI (dropdown klien, dibangun JS dari `CLIENTS` = `{id, name, remaining, booked, programs}` yang dikirim `DashboardController@index`): label `Nama (Sisa N sesi)`; `Nama (Sesi Habis - 0)` dan `Nama (Sisa N sesi, semua sudah terjadwal)` bersifat **disabled**; ada catatan "💡 Klien dengan sisa sesi 0 tidak dapat dijadwalkan. Silakan tambah/top-up sisa sesi terlebih dahulu lewat menu Klien -> Edit." Data `remaining`/`booked` di JS diperbarui lokal setelah tambah/hapus jadwal dan selesai sesi; validasi server tetap menjadi penjaga utama. Jam default = **jam sekarang bila tanggal = hari ini**, selain itu 09:00. Program otomatis bergilir: `activePrograms[count(jadwal klien non-cancelled) % jumlah program]`.

**Live session** (hanya dari jadwal yang ada):
1. **Mulai** → `POST /jadwal/{id}/mulai` mengisi `started_at = now()` (sekali). Panel "Sesi Sedang Berjalan" terbuka: stopwatch real-time (dihitung dari `started_at`), daftar gerakan program, kolom catatan PT. Bila alat suatu gerakan punya foto, di pojok kanan atas kotak gerakan tampil tombol ikon mata yang membuka foto alat di modal `#equipmentImageModal` (peta `EQUIPMENT_IMAGES` dari `DashboardController@index`); tanpa foto, tombol tidak dirender. Tiap gerakan punya **3 input compact sejajar** (`.live-inputs`, tinggi 46px untuk jempol): **Set**, **Repetisi** (teks singkat, mis. `12` / `10-12` / `30 detik`), **Beban (kg)** (desimal). Nilai awal diambil dari program: `default_sets`/`default_reps` dipecah server dari teks target (`"3 x 12"`) di `ScheduleController::payload()`, beban awal = `target_kg`. PT tinggal menyesuaikan sesuai kondisi lapangan.
2. **Kecilkan** menutup panel; banner LIVE di kalender; sesi berjalan otomatis dilanjutkan setelah refresh/pindah halaman (`GET /jadwal/berjalan`).
3. **Batalkan Sesi (salah klik)** → modal konfirmasi merah → `POST …/batal-mulai` mengosongkan `started_at`; jadwal kembali "Mulai"; sisa paket **tidak** berkurang; tidak ada WA.
4. **Selesai Sesi & Kirim ke WA 💖** → modal konfirmasi → `POST …/selesai` (`ScheduleController@finish`):
   - validasi performa (`name`, `equipment`, `sets` integer 0–99, `reps` teks ≤20, `sets_reps`, `actual_kg`) & `coach_note`; item disusun ulang menjadi skema `actual_performance_json` (lihat `01-DATABASE-AND-MODELS.md`) — `name` & `equipment` ikut disimpan;
   - transaksi: `status=completed`, `finished_at`, `duration_minutes`, `actual_performance_json`, `coach_note`, **`share_token = Str::random(32)`**; klien di-`lockForUpdate` lalu `remaining_sessions` **dikurangi 1 (hanya jika > 0)**;
   - kirim laporan WA via `GowaWhatsappService` (dibungkus try/catch);
   - respons `{schedule, wa_sent, message}`. Bila GoWA mati: data tetap tersimpan, notifikasi "Sesi tersimpan ✅ Tapi laporan WhatsApp belum terkirim (GoWA tidak aktif)." — **aplikasi tidak crash**.
5. Hapus jadwal: modal konfirmasi; ditolak (422) bila sudah `completed`.

## 6. Integrasi GoWA (`GowaWhatsappService`)

- Konfigurasi: `GOWA_BASE_URL`, `GOWA_USERNAME`, `GOWA_PASSWORD` (Basic Auth bila username terisi), opsional `GOWA_DEVICE_ID` → header `X-Device-Id` (GoWA multi-device).
- **Badge status** (kanan atas): `WA Aktif` (`bg-success-subtle text-success`) / `WA Tidak Aktif` (`bg-danger-subtle text-danger`) / `Cek WA...` (abu, belum dicek).
  - `cachedStatus()`: dibaca dari cache saja (tanpa HTTP) → dipakai saat render halaman lewat View composer (`$waState`). Tidak dikonfigurasi → `false`.
  - `checkStatus()`: `GET {base}/app/devices` (timeout 4 dtk), **di-cache 120 detik**; aktif bila respons sukses dan ada ≥1 perangkat. Dipanggil hanya dari endpoint `GET /wa-status`.
  - Browser: `public/js/app.js` mengecek `/wa-status` saat badge berstatus `checking` dan polling tiap 60 dtk. Header bersifat `data-turbo-permanent` sehingga badge tidak dirender ulang tiap pindah menu.
- **Kirim pesan** `sendMessage()`: `POST {base}/send/message` JSON `{phone: "62…@s.whatsapp.net", message}`; nomor dinormalisasi (`08…` → `628…`); semua error ditangkap → `false`.
- **Format laporan** (`buildSessionReport()`), urutan:
  1. Sapaan: "Halo kak {nama depan}! 💖" + terima kasih.
  2. *Ringkasan Sesi*: program, durasi (menit), sisa paket.
  3. *Pencapaian Gerakan Hari Ini 💪*: satu baris per gerakan dari nilai aktual, format `• Squat (Dumbbell): 3 set x 12 reps @ 10 kg` (alat "Tanpa Alat" tidak ditulis; tanpa beban tidak ada `@ … kg`; repetisi berisi unit seperti "30 detik" tidak diberi kata "reps"). Dibangun dari `Schedule::normalizedPerformance()` (`line`).
  4. *Catatan dari Coach Neil 📝* (jika ada).
  5. *Pengingat Nutrisi 🥗*: rentang `min g – max g per hari` (batas minimal & target optimal) + saran porsi pasca-latihan (30% target optimal).
  6. *Tips Pemulihan 😴* + kalimat motivasi penutup.
  7. Bila ada `share_token`: blok kartu progres (`📸 *Kartu Progres Mu:*`, kalimat pengantar, lalu **URL di baris baru**: `url('/progress/{token}')`).
  8. Footer `_- Gym Tracker by Neil_`.
- **`APP_URL` harus URL publik** agar link kartu progres bisa dibuka klien.

## 7. Kartu Progres IG Story (`/progress/{token}`)

- `PublicProgressController@show`: cari `Schedule` dengan `share_token` & `status=completed` (kalau tidak ada → 404). Hitung **Sesi ke-N** (urutan sesi selesai klien) dan total = sesi selesai + sisa paket.
- View mandiri `resources/views/public/progress.blade.php` (tanpa navigasi admin, `noindex`). Area `#storyCardArea` rasio **9:16** (lebar 360px), gradasi pink `#ec4899 → #f43f5e`, kartu putih. Klien **Pria** → class `theme-navy` (gradasi navy).
- Isi: header `✨ WORKOUT COMPLETED • {tanggal}`, nama klien, label program, 3 statistik (**Durasi** `X Menit`, **Sesi Ke-** `n/total`, **Protein/hari** `min–max g`), daftar **Highlight Gerakan & Beban Hari Ini** (nama gerakan, **set × repetisi aktual**, dan beban riil; dari `normalizedPerformance()`), kutipan catatan coach, footer **"Personal Trainer: Coach Neil 🌸"** (💪 untuk pria) + watermark "Gym Tracker by Neil".
- Tombol **"Simpan Gambar untuk IG Story 📸"**: `html2canvas@1.4.1` (cdnjs), `scale: 2`, mengunduh `Progress-{slug-nama}-{YYYY-MM-DD}.png`.
- Halaman ini publik dan bukan bagian Turbo/PWA shell.

## 8. PWA & SPA (perilaku, ringkas)

Lihat `00-PROJECT-OVERVIEW.md` §6 dan `03-AI-AGENT-RULES.md`. File: `public/manifest.json` (standalone, theme `#ec4899`, background `#fdf2f8`), `public/sw.js`, `public/offline.html`, `public/images/*`, `public/js/app.js`.
