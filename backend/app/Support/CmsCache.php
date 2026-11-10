<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\HomeController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Invalidasi cache setelah konten CMS berubah:
 *  1. cache aplikasi (agregat beranda, pengaturan)
 *  2. webhook revalidasi frontend Next.js (POST {FRONTEND_URL}/api/revalidate, header x-revalidate-secret)
 */
final class CmsCache
{
    /** @param string[] $tags tag cache frontend, mis. ['cms'] atau ['posts', 'post:slug'] */
    public static function flush(array $tags = ['cms']): void
    {
        Cache::forget(HomeController::CACHE_KEY);
        foreach (['site', 'hero', 'stats', 'about', 'why', 'cta', 'company', 'post_categories'] as $key) {
            Cache::forget("settings.$key");
        }

        $secret = (string) config('services.frontend.revalidate_secret');
        $base = rtrim((string) explode(',', (string) config('services.frontend.url'))[0], '/');
        if ($secret === '' || $base === '') {
            return;
        }

        try {
            Http::timeout(5)->withHeaders(['x-revalidate-secret' => $secret])->post("$base/api/revalidate", ['tags' => array_values(array_unique($tags))]);
        } catch (\Throwable $e) {
            Log::warning('Revalidasi frontend gagal: '.$e->getMessage());
        }
    }
}
