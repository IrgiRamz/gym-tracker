<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GowaWhatsappService
{
    public function configured(): bool
    {
        return filled(config('services.gowa.base_url'));
    }

    private function http()
    {
        $req = Http::timeout(8)->acceptJson();
        $user = config('services.gowa.username');
        if (filled($user)) {
            $req = $req->withBasicAuth($user, (string) config('services.gowa.password'));
        }
        // GoWA multi-device: pilih device lewat header X-Device-Id (opsional)
        if (filled(config('services.gowa.device_id'))) {
            $req = $req->withHeaders(['X-Device-Id' => config('services.gowa.device_id')]);
        }
        return $req;
    }

    private function url(string $path): string
    {
        return rtrim(config('services.gowa.base_url'), '/') . $path;
    }

    /**
     * Status dari cache saja, TANPA request HTTP (aman dipanggil di setiap render halaman).
     * true/false = hasil cek terakhir; null = belum pernah dicek (browser akan mengecek via AJAX).
     */
    public function cachedStatus(): ?bool
    {
        if (! $this->configured()) {
            return false;
        }
        $v = Cache::get('gowa_status');
        return is_bool($v) ? $v : null;
    }

    /** Cek koneksi GoWA (di-cache 2 menit; dipanggil dari endpoint AJAX, bukan saat render halaman). */
    public function checkStatus(bool $fresh = false): bool
    {
        if (! $this->configured()) {
            return false;
        }
        if ($fresh) {
            Cache::forget('gowa_status');
        }
        return Cache::remember('gowa_status', 120, function () {
            try {
                $res = $this->http()->timeout(4)->get($this->url('/app/devices'));
                if (! $res->successful()) {
                    return false;
                }
                $results = $res->json('results');
                if (! is_array($results)) {
                    return false;
                }
                // Instance GoWA ini dipakai bersama: /app/devices mengembalikan device SEMUA tenant.
                // Aktif hanya bila device KITA (GOWA_DEVICE_ID) ada di daftar DAN jid-nya terisi
                // (jid kosong = device terdaftar tapi belum di-scan/link ke WhatsApp).
                $deviceId = config('services.gowa.device_id');
                foreach ($results as $d) {
                    if (($d['device'] ?? null) === $deviceId) {
                        return filled($d['jid'] ?? null);
                    }
                }
                return false;
            } catch (Throwable $e) {
                return false;
            }
        });
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        }
        return $digits;
    }

    /** Kirim pesan teks. Tidak pernah melempar exception. */
    public function sendMessage(string $phone, string $message): bool
    {
        if (! $this->configured()) {
            return false;
        }
        try {
            $res = $this->http()->asJson()->post($this->url('/send/message'), [
                'phone' => $this->normalizePhone($phone) . '@s.whatsapp.net',
                'message' => $message,
            ]);
            if (! $res->successful()) {
                Log::warning('GoWA gagal kirim', ['status' => $res->status(), 'body' => $res->body()]);
            }
            return $res->successful();
        } catch (Throwable $e) {
            Log::warning('GoWA error: ' . $e->getMessage());
            return false;
        }
    }

    /** Susun pesan laporan sesi latihan. */
    public function buildSessionReport(Schedule $schedule): string
    {
        $client = $schedule->client;
        $first = explode(' ', trim($client->name))[0];
        $program = $schedule->program?->day_label ?? 'Latihan Bersama PT';

        $lines = [];
        $lines[] = "Halo kak {$first}! 💖";
        $lines[] = "Terima kasih banyak ya sudah latihan hari ini, kakak keren banget! 🌸";
        $lines[] = '';
        $lines[] = '*Ringkasan Sesi*';
        $lines[] = "• Program: {$program}";
        $lines[] = "• Durasi: {$schedule->duration_minutes} menit";
        $lines[] = "• Sisa paket sesi: {$client->remaining_sessions}x";
        $lines[] = '';
        // Rincian gerakan (termasuk per set) SENGAJA tidak dicantumkan di WA agar pesan tetap ringkas;
        // detailnya hidup di kartu progres (tautan di bawah) dan di History aplikasi.
        if (filled($schedule->coach_note)) {
            $lines[] = '';
            $lines[] = '*Catatan dari Coach Neil* 📝';
            $lines[] = $schedule->coach_note;
        }
        $max = $client->daily_protein_max ?: $client->daily_protein_target;
        $lines[] = '';
        $lines[] = '*Pengingat Nutrisi* 🥗';
        if ($client->daily_protein_min && $client->daily_protein_max) {
            $lines[] = "Target protein harian kakak: *{$client->daily_protein_min}g – {$client->daily_protein_max}g per hari*";
            $lines[] = "(batas minimal aman {$client->daily_protein_min}g, target optimal {$client->daily_protein_max}g).";
        } else {
            $lines[] = "Target protein harian kakak: *{$max} g*.";
        }
        $lines[] = 'Setelah latihan, yuk makan dalam 1-2 jam: ' . Client::proteinFoodHint((int) round($max * 0.3)) . '.';
        $lines[] = '';
        $lines[] = '*Tips Pemulihan* 😴';
        $lines[] = 'Minum air putih yang cukup, tidur 7-8 jam, dan lakukan peregangan ringan ya kak. Otot tumbuh saat istirahat!';
        $lines[] = '';
        $lines[] = 'Kamu sudah melangkah lebih dekat ke tujuanmu. Aku bangga sama kamu! Sampai ketemu di sesi berikutnya ✨💖';
        if (filled($schedule->share_token)) {
            $lines[] = '';
            $lines[] = '📸 *Kartu Progres Mu:*';
            $lines[] = 'Lihat & simpan kartu latihanmu hari ini di sini ya:';
            $lines[] = url('/progress/' . $schedule->share_token);
        }
        $lines[] = '';
        $lines[] = '_- Gym Tracker by Neil_';

        return implode("\n", $lines);
    }
}
