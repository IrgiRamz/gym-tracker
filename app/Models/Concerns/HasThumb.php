<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Gambar kecil (thumbnail) untuk daftar: file asli foto HP bisa 2 MB lebih,
 * padahal di daftar tampilnya cuma 52px. Versi kecil (maks 480px, JPEG q80)
 * disimpan di subfolder "thumbs" di samping file asli; file asli tetap
 * dipakai untuk tampilan penuh (modal). Bila GD tidak tersedia atau
 * formatnya bukan gambar diam, pembuatan dilewati dan URL jatuh ke asli.
 */
trait HasThumb
{
    public static function thumbPathFor(string $path): string
    {
        return dirname($path) . '/thumbs/' . pathinfo($path, PATHINFO_FILENAME) . '.jpg';
    }

    public static function makeThumb(?string $path): void
    {
        if (! $path || ! extension_loaded('gd')) {
            return;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return;
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return;
        }
        $src = @imagecreatefromstring($disk->get($path));
        if (! $src) {
            return;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w * $h > 20_000_000) { // foto raksasa: jangan habiskan memori worker
            imagedestroy($src);
            return;
        }
        $scale = min(1, 480 / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, 80);
        $data = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        if ($data) {
            $disk->put(static::thumbPathFor($path), $data);
        }
    }

    public static function deleteThumb(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete(static::thumbPathFor($path));
        }
    }

    /** URL versi kecil bila sudah dibuat; jatuh ke URL file asli bila belum ada. */
    public function thumbUrlFor(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $disk = Storage::disk('public');
        $thumb = static::thumbPathFor($path);
        return $disk->exists($thumb) ? $disk->url($thumb) : $disk->url($path);
    }
}
