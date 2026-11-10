<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/** URL publik untuk file media CMS: path hasil unggah → URL disk (public/S3); URL absolut & path /images lama dibiarkan. */
final class Media
{
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::disk(config('filesystems.default'))->url($path);
    }
}
