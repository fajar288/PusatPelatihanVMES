<?php

namespace App\Support;

/**
 * Kompresi gambar di server dengan GD: perkecil ke batas sisi terpanjang (atau potong persegi) lalu simpan sebagai WebP.
 * Bila GD/WebP tidak tersedia atau berkas tidak bisa dibaca, mengembalikan null agar pemanggil menyimpan berkas asli.
 */
final class ImageProcessor
{
    public static function supported(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagewebp') && (imagetypes() & IMG_WEBP);
    }

    /**
     * @param  int  $max  sisi terpanjang hasil (px); gambar yang lebih kecil tidak diperbesar
     * @param  bool  $square  potong tengah menjadi persegi $max x $max (foto profil)
     * @return string|null byte WebP, atau null bila tidak bisa diproses
     */
    public static function toWebp(string $binary, int $max, bool $square = false, int $quality = 82): ?string
    {
        if (! self::supported() || $binary === '') {
            return null;
        }
        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return null;
        }
        $src = self::orient($src, $binary);
        $w = imagesx($src);
        $h = imagesy($src);

        if ($square) {
            $side = min($w, $h);
            $sx = intdiv($w - $side, 2);
            $sy = intdiv($h - $side, 2);
            $dw = $dh = min($max, $side);
            $sw = $sh = $side;
        } else {
            $scale = min(1, $max / max($w, $h));
            $dw = max(1, (int) round($w * $scale));
            $dh = max(1, (int) round($h * $scale));
            [$sx, $sy, $sw, $sh] = [0, 0, $w, $h];
        }

        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);
        imagedestroy($src);

        ob_start();
        $ok = imagewebp($dst, null, $quality);
        $out = ob_get_clean();
        imagedestroy($dst);

        return $ok && is_string($out) && $out !== '' ? $out : null;
    }

    /** Putar foto JPEG sesuai tag EXIF Orientation (foto ponsel) agar tidak tampil miring setelah EXIF dibuang. */
    private static function orient(\GdImage $img, string $binary): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($binary, "\xFF\xD8")) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($binary));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);
        if ($rotated === false) {
            return $img;
        }
        imagedestroy($img);

        return $rotated;
    }
}
