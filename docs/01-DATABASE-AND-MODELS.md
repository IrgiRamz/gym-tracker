# 01 — Database & Models

> Sumber kebenaran: `database/migrations/*` dan `app/Models/*`. Urutan migration aktual:
> `0001_01_01_000000_create_users_table` (users, password_reset_tokens, **sessions**) · `…000001_create_cache_table` · `…000002_create_jobs_table` ·
> `2026_01_01_000001_create_equipments_table` · `…000002_create_clients_table` · `…000003_create_workout_programs_table` · `…000004_create_schedules_table` ·
> `…000005_create_weight_logs_table` · `…000006_add_share_token_to_schedules_table` · `…000007_change_body_focus_to_json_on_clients_table` · `…000008_add_protein_range_to_clients_table`.

## Diagram relasi

```
User (PT)                  — berdiri sendiri (hanya untuk login)
Equipment                  — berdiri sendiri (master alat; dibaca AiWorkoutService)

Client 1 ──< WorkoutProgram     (clients.id → workout_programs.client_id, ON DELETE CASCADE)
Client 1 ──< Schedule           (clients.id → schedules.client_id, ON DELETE CASCADE)
Client 1 ──< WeightLog          (clients.id → weight_logs.client_id, ON DELETE CASCADE)
WorkoutProgram 1 ──< Schedule   (schedules.workout_program_id, NULLABLE, ON DELETE SET NULL)
```

Menghapus klien menghapus semua program, jadwal, dan log berat badannya (cascade).

## Tabel

### `users` (bawaan Laravel)
`id, name, email (unique), email_verified_at, password (hashed), remember_token, timestamps`.
Tabel pendamping: `password_reset_tokens`, **`sessions`** (dipakai `SESSION_DRIVER=database`). Akun PT default dibuat di `DatabaseSeeder` (lihat file seeder untuk kredensial; **jangan menaruh email/password asli di dokumentasi**). Login memakai **Email atau Nama (`users.name`) + Password** — tidak ada kolom username; `name` tidak unik (lihat `02` §1).

### `equipments`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| name | string | mis. "Dumbbell (1 - 25 kg)" |
| category | string | salah satu `Equipment::CATEGORIES`: `Beban Bebas`, `Mesin Latihan`, `Kardio & Matras`, `Tanpa Alat` |
| is_available | boolean default `true` | hanya alat `true` yang dikirim ke AI |
| image_path | string nullable | path foto alat di disk `public` (folder `equipments`), diunggah opsional dari halaman Alat Gym; `null` = tidak ada foto. Dihapus dari disk saat foto diganti/dihapus, alat dihapus, atau reset semua alat |
| timestamps | | |

Model `Equipment` memakai `protected $table = 'equipments'`.

### `clients`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint PK | |
| name | string | |
| phone | string | format bebas (regex `^[0-9+\s-]{9,18}$`); dinormalisasi ke `62…` saat kirim WA |
| gender | string | `Wanita` \| `Pria` |
| weight_kg | decimal(5,1) | berat **saat ini** (riwayat di `weight_logs`) |
| height_cm | unsignedSmallInt, nullable | opsional (100–230) |
| waist_cm | unsignedSmallInt, nullable | lingkar perut (cm), opsional (40–250); hanya diisi bila klien nyaman diukur |
| goal | string | `Client::GOALS`: `Bentuk & Kencangkan`, `Turun Berat Badan`, `Naik Massa Otot`, `Bugar & Sehat`, `Persiapan Lari / Marathon` |
| goal_note | string(200), nullable | alasan/tujuan khusus bebas dari klien (opsional), ikut dikirim ke prompt AI |
| **body_focus** | **json** | **array string, multi-select** (lihat bawah) |
| pt_frequency_per_week | unsignedTinyInt default 2 | 1, 2, atau 3 |
| activity_level | string default `Cukup Aktif` | `Baru Mulai / Santai` \| `Cukup Aktif` \| `Sangat Aktif` |
| remaining_sessions | unsignedSmallInt default 0 | sisa paket sesi |
| **daily_protein_min** | unsignedSmallInt default 0 | batas minimal aman (gram/hari) |
| **daily_protein_max** | unsignedSmallInt default 0 | target optimal (gram/hari) |
| daily_protein_target | unsignedSmallInt default 0 | **kolom lama, dipertahankan = `daily_protein_max`** (kompatibilitas) |
| notes | text, nullable | |
| timestamps | | |

**`body_focus` (JSON array, cast `array`)** — pilihan di `Client::FOCUSES`:
`Bokong (Glutes)`, `Kaki & Paha`, `Dada (Chest)`, `Punggung (Back)`, `Lengan & Bahu`, `Perut & Pinggang`, `Seluruh Tubuh`.
Aturan: minimal 1 pilihan. Jika `Seluruh Tubuh` ikut terpilih, **hanya** `Seluruh Tubuh` yang disimpan (`Client::normalizeFocus()` di server; UI juga saling menonaktifkan). Migration `…000007` mengubah kolom dari string ke json dan memetakan data lama (mis. "Kaki & Bokong" → `["Bokong (Glutes)","Kaki & Paha"]`).

**Rentang protein** (`Client::PROTEIN_MATRIX`, `ACTIVITIES`, `proteinTier()`, `calcProteinRange()`):
- Matriks `tujuan → gender → [tier1, tier2, tier3]`, tiap tier `[pengali_min, pengali_max]` gram per kg.
- **Tier dasar** dari frekuensi: 1x→1, 2x→2, 3x→3. **Keaktifan** menggeser tier: `Baru Mulai / Santai` = −1, `Cukup Aktif` = 0, `Sangat Aktif` = +1; hasil dijepit ke 1–3.
- Hasil gram = `floor(((round(berat*10)) * round(pengali*10)) / 100 + 0.5)` (aritmetika bulat agar identik dengan JavaScript).
- Disimpan ke `daily_protein_min`, `daily_protein_max`, dan `daily_protein_target = max`. Dihitung ulang saat: klien dibuat, klien diedit, berat badan diperbarui.
- Matriks dikirim ke JS lewat `@json(Client::PROTEIN_MATRIX)` → **satu sumber kebenaran**; jangan menduplikasi angka di JS.

Helper model: `focusText()`, `proteinRangeText(sep)` (mis. `81g – 92g`), `proteinFoodHint(grams)` (setara ayam/telur/tempe).

### `workout_programs`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | PK | |
| client_id | FK → clients (cascade) | |
| cycle_number | unsignedSmallInt default 1 | siklus ke-N; naik 1 setiap generate ulang |
| day_label | string | mis. "Sesi A - Bokong (Glutes)" |
| exercises_json | json (cast `array`) | array gerakan (di bawah) |
| is_active | boolean default true | hanya program aktif yang dipakai; program lama dinonaktifkan, **tidak dihapus** |

Bentuk elemen `exercises_json`:
```json
{ "name": "Hip Thrust", "equipment": "Barbell & Plate", "sets_reps": "3 x 12", "target_kg": 12.0, "tip": "Remas bokong di puncak gerakan." }
```
`equipment` bernilai nama alat dari tabel `equipments` atau `"Tanpa Alat"`. Jumlah gerakan per sesi: **4–5** (sudah termasuk pemanasan).

### `schedules`
| Kolom | Tipe | Catatan |
|---|---|---|
| id | PK | |
| client_id | FK → clients (cascade) | |
| workout_program_id | FK nullable → workout_programs (set null) | |
| scheduled_date | date | index |
| scheduled_time | time | |
| status | string default `scheduled` | `scheduled` \| `completed` \| `cancelled` (**saat ini tidak ada fitur yang mengisi `cancelled`**; menghapus jadwal = delete baris; kalender dan history mengabaikan `cancelled`) |
| started_at | timestamp nullable | diisi saat "Mulai"; `null` lagi bila sesi dibatalkan |
| finished_at | timestamp nullable | |
| duration_minutes | unsignedSmallInt nullable | `max(1, round(detik/60))` |
| actual_performance_json | json nullable (cast `array`) | nilai **aktual lapangan** per gerakan, lihat skema di bawah tabel |
| coach_note | text nullable | catatan PT |
| **share_token** | string unique nullable | `Str::random(32)`, dibuat saat sesi selesai; dipakai `/progress/{token}` |
| timestamps | | |

**Skema `actual_performance_json`** (array, satu elemen per gerakan; disimpan saat "Selesai Sesi"):
```json
{ "name": "Pec Deck", "equipment": "Mesin Pec Deck", "sets": 3, "reps": "12", "sets_reps": "3 x 12", "actual_kg": 10.0,
  "sets_detail": [ { "reps": "12", "kg": 5.0 }, { "reps": "10", "kg": 7.5 }, { "reps": "8", "kg": 10.0 } ] }
```
- `name` & `equipment` disalin dari program saat sesi berjalan → riwayat tetap utuh walau program klien diganti/di-generate ulang.
- **`sets_detail`** (format per-set, yang utama sejak fitur live session per-set): repetisi & beban **tiap set**; jumlah baris fleksibel (PT bisa tambah/kurang set saat sesi). Agregat diturunkan darinya: `sets` = jumlah baris, `actual_kg` = beban terberat (top set), `reps` = repetisi set pertama.
- `sets` integer atau `null`; `reps` **teks** atau `null` (mis. `"12"`, `"10-12"`, `"30 detik"`, `"10 /kaki"`); `actual_kg` desimal (`0` = bodyweight).
- `sets_reps` = `"{sets} x {reps}"` bila keduanya terisi, jika tidak berisi target program (kompatibilitas format lama).
- **Format lama** (sesi sebelum fitur per-set) tanpa `sets_detail`; yang lebih lama lagi hanya `{name, sets_reps, actual_kg}`. **Jangan membaca JSON ini langsung** untuk tampilan — pakai `Schedule::normalizePerformance()` / `$schedule->normalizedPerformance()` yang menyeragamkan semua format, lalu menyediakan `sets_label` ("3 set x 12 reps"), `line`, `sets_detail`, dan `detail_label` ringkas per set ("S1 12x5kg · S2 10x7.5kg · S3 8x10kg"). Untuk format per-set, `kg` hasil normalisasi = top set dan `reps` hanya terisi bila semua set sama. Parser target program: `Schedule::parseSetsReps("3 x 12") → [3, "12"]`.

Casting model `Schedule`: `scheduled_date` → `date:Y-m-d`, `started_at`/`finished_at` → datetime, `actual_performance_json` → array.

### `weight_logs`
`id, client_id (FK cascade), weight_kg decimal(5,1), recorded_at date, timestamps`. Satu baris dibuat saat klien dibuat (berat awal) dan setiap berat berubah (modal **BB** atau form **Edit**). Klien lama tanpa log: berat sebelumnya disimpan dulu sebagai titik awal. `Client::weightLogs()` terurut `recorded_at`, `id`.

### Tabel infrastruktur
`cache` / `cache_locks` (CACHE_STORE=database), `jobs` / `job_batches` / `failed_jobs`, `sessions`.

## Model → relasi (kode)

| Model | Relasi |
|---|---|
| `Client` | `programs()` hasMany WorkoutProgram · `activePrograms()` (is_active, order id) · `schedules()` · `weightLogs()` |
| `WorkoutProgram` | `client()` belongsTo |
| `Schedule` | `client()` belongsTo · `program()` belongsTo WorkoutProgram via `workout_program_id` |
| `WeightLog` | tanpa relasi balik (diakses lewat Client) |
| `Equipment`, `User` | tanpa relasi |

## Session login 1 tahun

- `.env`: `SESSION_LIFETIME=525600` (menit = 1 tahun), `SESSION_DRIVER=database`.
- `AuthController@login` memakai `Auth::attempt($credentials, true)` → **remember = true selalu**.
- Semua route aplikasi dibungkus middleware `auth`; `/login` dibungkus `guest` (sudah login → redirect ke `/`).

## Seeder (`DatabaseSeeder`)

Membuat: 1 akun PT; **31 alat gym komersial** (tanpa alat home workout seperti matras/resistance band) — *Beban Bebas* (8): Dumbbell 1–25 kg, Dumbbell Berat 26–40 kg, Barbell & Plate, EZ Bar, Kettlebell, Flat Bench, Adjustable Incline Bench, Squat/Power Rack; *Mesin Latihan* (17): Leg Press, Lat Pulldown & Seated Row, Smith Machine, Cable Crossover, Chest Press, Pec Deck, Shoulder Press, Leg Extension, Leg Curl, Hack Squat, Hip Abduction/Adduction, Glute Kickback, Hip Thrust, Seated Calf Raise, Back Extension Bench, Assisted Pull-Up/Dip, Ab Crunch; *Kardio* (6, kategori `Kardio & Matras`): Treadmill, Elliptical, Stationary Bike, Spin Bike, Stair Climber, Rowing Machine. Seeder memakai `firstOrCreate` per nama, jadi menjalankan `db:seed` pada database yang sudah ada **menambah** alat baru tetapi **tidak menghapus** alat lama (mis. "Matras Yoga" harus dihapus manual lewat menu Alat Gym atau pakai `migrate:fresh --seed`). Urutan daftar penting untuk pencocokan kata kunci template AI. 3 klien wanita contoh (Aulia Rahma, Sinta Maharani, Dewi Anggraini) lengkap dengan `body_focus` array, rentang protein, dan program dari `AiWorkoutService::fallback()`; beberapa jadwal di sekitar hari ini (sebagian `completed` dengan `share_token`). `php artisan migrate:fresh --seed` mereset semuanya.
