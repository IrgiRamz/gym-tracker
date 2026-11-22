<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Kartu Progres {{ $client->name }} - Gym Tracker by Neil</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Tema default: pink (klien wanita) */
        :root { --g1: #ec4899; --g2: #f43f5e; --accent: #ec4899; --dark: #db2777; --soft: #fdf2f8; --border: #fbcfe8; --muted: #9d6b86; --faint: #c08aa6; --ink: #3b1d2f; --shadow: rgba(131, 24, 67, .25); }
        /* Tema navy (klien pria) */
        body.theme-navy { --g1: #1e3a8a; --g2: #0f172a; --accent: #1e40af; --dark: #1e3a8a; --soft: #eff6ff; --border: #bfdbfe; --muted: #64748b; --faint: #94a3b8; --ink: #0f172a; --shadow: rgba(15, 23, 42, .35); }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; background: var(--soft); min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding: 20px 14px 40px; color: var(--ink); }
        .hint { font-size: .8rem; color: var(--muted); margin-bottom: 14px; text-align: center; }
        /* Tinggi minimal = rasio 9:16 pada lebar kartu, tapi kartu MEMANJANG mengikuti isi bila kontennya
           panjang (mis. 5 gerakan dengan rincian per set) supaya tidak ada isi yang terpotong. */
        #storyCardArea { width: 360px; max-width: 100%; min-height: calc(min(360px, 100vw - 28px) * 16 / 9); background: linear-gradient(160deg, var(--g1) 0%, var(--g2) 100%); padding: 22px 18px; display: flex; flex-direction: column; border-radius: 28px; position: relative; overflow: hidden; }
        #storyCardArea::before { content: ''; position: absolute; width: 220px; height: 220px; border-radius: 50%; background: rgba(255,255,255,.12); top: -70px; right: -70px; }
        .head { color: #fff; font-size: .68rem; font-weight: 800; letter-spacing: .08em; text-align: center; margin-bottom: 14px; position: relative; }
        .card { background: #fff; border-radius: 22px; padding: 18px 16px; flex: 1; display: flex; flex-direction: column; position: relative; box-shadow: 0 10px 30px var(--shadow); }
        .name { font-size: 1.45rem; font-weight: 800; line-height: 1.15; }
        .prog { font-size: .8rem; font-weight: 700; color: var(--accent); margin: 4px 0 14px; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 14px; }
        .stat { background: var(--soft); border: 1px solid var(--border); border-radius: 14px; padding: 10px 4px; text-align: center; }
        .stat b { display: block; font-size: .95rem; font-weight: 800; color: var(--dark); }
        .stat span { font-size: .6rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .03em; }
        .sec { font-size: .66rem; font-weight: 800; letter-spacing: .08em; color: var(--muted); margin-bottom: 6px; }
        .ex { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 7px 0; border-bottom: 1px dashed var(--border); font-size: .8rem; }
        .ex:last-child { border-bottom: none; }
        .ex .n { font-weight: 700; }
        .ex .n small { display: block; font-weight: 500; color: var(--muted); font-size: .66rem; }
        .ex .kg { background: var(--accent); color: #fff; font-weight: 800; border-radius: 999px; padding: 3px 10px; font-size: .74rem; white-space: nowrap; }
        .quote { margin-top: 12px; background: var(--soft); border-left: 4px solid var(--accent); border-radius: 12px; padding: 10px 12px; font-size: .78rem; font-style: italic; line-height: 1.45; }
        .quote small { display: block; font-style: normal; font-weight: 700; color: var(--dark); margin-top: 4px; font-size: .68rem; }
        .spacer { flex: 1; }
        .foot { text-align: center; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border); }
        .foot b { font-size: .85rem; font-weight: 800; }
        .foot small { display: block; font-size: .6rem; color: var(--faint); margin-top: 3px; font-weight: 600; }
        .btn { margin-top: 18px; width: 360px; max-width: 100%; border: none; background: var(--accent); color: #fff; font: 800 1rem 'Plus Jakarta Sans', sans-serif; padding: 15px; border-radius: 16px; cursor: pointer; box-shadow: 0 8px 20px var(--shadow); }
        .btn:disabled { opacity: .7; }
    </style>
</head>
<body class="{{ $client->gender === 'Pria' ? 'theme-navy' : '' }}">
    <div class="hint">Kartu latihanmu siap dibagikan {{ $client->gender === 'Pria' ? '💪' : '💖' }}</div>

    <div id="storyCardArea">
        <div class="head">✨ WORKOUT COMPLETED • {{ $s->scheduled_date->translatedFormat('d F Y') }}</div>
        <div class="card">
            <div class="name">{{ $client->name }}</div>
            <div class="prog">{{ $s->program?->day_label ?? 'Latihan Bersama PT' }}</div>

            <div class="stats">
                <div class="stat"><b>{{ $s->duration_minutes }} Menit</b><span>Durasi</span></div>
                <div class="stat"><b>{{ $sessionNo }}/{{ $sessionTotal }}</b><span>Sesi</span></div>
                <div class="stat"><b style="font-size:.82rem">{{ $client->proteinRangeText('–') }}</b><span>Protein / Hari</span></div>
            </div>

            @if(!empty($s->actual_performance_json))
                <div class="sec">HIGHLIGHT GERAKAN &amp; BEBAN HARI INI</div>
                @foreach($s->normalizedPerformance() as $p)
                    @php $kg = (float) ($p['kg'] ?? 0); @endphp
                    <div class="ex">
                        <div class="n">{{ $p['name'] }}<small>{{ $p['sets_label'] }}</small>@if($p['detail_label'])<small>{{ $p['detail_label'] }}</small>@endif</div>
                        <div class="kg">{{ $kg > 0 ? rtrim(rtrim(number_format($kg, 1, '.', ''), '0'), '.') . ' kg' : 'Body weight' }}</div>
                    </div>
                @endforeach
            @endif

            @if(filled($s->coach_note))
                <div class="quote">"{{ $s->coach_note }}"<small>- Coach Neil</small></div>
            @endif

            <div class="spacer"></div>
            <div class="foot">
                <b>Personal Trainer: Coach Neil {{ $client->gender === 'Pria' ? '💪' : '🌸' }}</b>
                <small>Gym Tracker by Neil</small>
            </div>
        </div>
    </div>

    <button class="btn" id="saveBtn">Simpan Gambar untuk IG Story 📸</button>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>
        const NAME = @json(\Illuminate\Support\Str::slug($client->name));
        const DATE = @json($s->scheduled_date->format('Y-m-d'));
        document.getElementById('saveBtn').addEventListener('click', async function () {
            const btn = this;
            btn.disabled = true; btn.textContent = 'Menyiapkan gambar...';
            try {
                if (document.fonts && document.fonts.ready) await document.fonts.ready;
                const canvas = await html2canvas(document.getElementById('storyCardArea'), { scale: 2, useCORS: true, backgroundColor: null });
                const a = document.createElement('a');
                a.download = `Progress-${NAME}-${DATE}.png`;
                a.href = canvas.toDataURL('image/png');
                document.body.appendChild(a); a.click(); a.remove();
                btn.textContent = 'Tersimpan! Simpan lagi 📸';
            } catch (e) {
                btn.textContent = 'Gagal, coba lagi ya 📸';
            }
            btn.disabled = false;
        });
    </script>
</body>
</html>
