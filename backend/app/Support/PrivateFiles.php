<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Berkas data pribadi/berbayar (bukti transfer, tugas peserta, dokumentasi logbook, materi LMS) disimpan di disk
 * privat tanpa URL publik dan hanya disajikan lewat endpoint yang memeriksa hak akses atau URL sementara bertanda
 * tangan. Berkas lama yang masih di disk default (public) tetap ditemukan lewat {@see self::locate()} sampai
 * dipindahkan dengan `php artisan files:privatize`.
 */
final class PrivateFiles
{
    /** Folder yang isinya wajib privat (dipakai juga oleh perintah files:privatize). */
    public const DIRECTORIES = ['payments', 'lms/submissions', 'lms/resources', 'monitoring/logbooks'];

    public static function diskName(): string
    {
        return (string) config('filesystems.private_disk', 'local');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function store(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, self::diskName());
        if ($path === false) {
            throw new \RuntimeException('Berkas gagal disimpan.');
        }

        return $path;
    }

    /** Disk tempat berkas berada: disk privat dulu, lalu disk default untuk berkas lama. Null bila tidak ada. */
    public static function locate(?string $path): ?Filesystem
    {
        $name = self::locateDisk($path);

        return $name === null ? null : Storage::disk($name);
    }

    /** Nama disk tempat berkas berada (privat dulu, lalu default), atau null. */
    public static function locateDisk(?string $path): ?string
    {
        if ($path === null || $path === '' || str_contains($path, '://')) {
            return null;
        }
        foreach (array_unique([self::diskName(), (string) config('filesystems.default')]) as $name) {
            if (Storage::disk($name)->exists($path)) {
                return $name;
            }
        }

        return null;
    }

    public static function delete(?string $path): void
    {
        self::locate($path)?->delete($path);
    }

    /**
     * URL untuk admin: URL sementara bertanda tangan bila berkas di disk privat, URL biasa untuk berkas lama di disk
     * public. Null bila berkas tidak ada.
     */
    public static function url(?string $path, int $minutes = 30): ?string
    {
        $name = self::locateDisk($path);
        if ($name === null) {
            return null;
        }
        $disk = Storage::disk($name);
        if ($name === self::diskName()) {
            try {
                return $disk->temporaryUrl($path, now()->addMinutes($minutes));
            } catch (\Throwable) {
                // driver tanpa URL sementara: jatuh ke URL biasa
            }
        }

        return $disk->url($path);
    }
}
