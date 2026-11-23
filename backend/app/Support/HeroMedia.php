<?php

namespace App\Support;

use App\Filament\Support\Fields;
use App\Models\AuditLog;
use App\Models\Banner;
use App\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slide foto hero dan logo "Partner Resmi" di beranda. Keduanya bagian dari HeroData frontend, tetapi sumbernya tabel
 * banners (posisi hero) dan partners (is_official), bukan kunci pengaturan `hero` (HomeController menggabungkannya).
 * Editor Beranda menyunting baris tabel itu langsung agar hanya ada satu sumber data.
 */
final class HeroMedia
{
    /** @return list<array<string, mixed>> state repeater slide (urut tampil) */
    public static function slides(): array
    {
        return Banner::query()->where('position', 'hero')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Banner $b) => ['id' => $b->id, ...Fields::imageState('image', $b->image), 'alt' => (string) $b->alt])->all();
    }

    /** @return list<array<string, mixed>> state repeater mitra resmi (urut tampil) */
    public static function officialPartners(): array
    {
        return Partner::query()->where('is_official', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Partner $p) => ['id' => $p->id, 'name' => $p->name, ...Fields::imageState('logo', $p->logo)])->all();
    }

    /**
     * Terapkan isian repeater: baris lama diperbarui, baru dibuat, urutan mengikuti repeater. Slide yang dihapus dari
     * repeater ikut dihapus; mitra yang dihapus hanya dilepas dari "Partner Resmi" (tetap tampil di halaman Mitra).
     * Hanya baris yang berubah yang disimpan, agar revalidasi frontend tidak dikirim berkali-kali tanpa perlu.
     *
     * @param  array<array-key, array<string, mixed>>  $slides
     * @param  array<array-key, array<string, mixed>>  $partners
     * @return bool true bila ada perubahan
     */
    public static function sync(array $slides, array $partners): bool
    {
        $before = ['slides' => self::snapshotSlides(), 'officialPartners' => self::snapshotPartners()];

        DB::transaction(function () use ($slides, $partners) {
            self::syncSlides(array_values($slides));
            self::syncPartners(array_values($partners));
        });

        $after = ['slides' => self::snapshotSlides(), 'officialPartners' => self::snapshotPartners()];
        if ($after === $before) {
            return false;
        }
        AuditLog::record('settings.hero_media_updated', null, $before, $after);

        return true;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function syncSlides(array $rows): void
    {
        $existing = Banner::query()->where('position', 'hero')->get()->keyBy('id');
        $keep = [];
        $order = 0;
        foreach ($rows as $row) {
            $image = Fields::imageSource($row, 'image');
            if ($image === null) {
                continue;
            }
            $banner = $existing->get((int) ($row['id'] ?? 0)) ?? new Banner(['position' => 'hero', 'is_active' => true]);
            $banner->fill(['image' => $image, 'alt' => trim((string) ($row['alt'] ?? '')) ?: null, 'sort_order' => ++$order]);
            if (! $banner->exists || $banner->isDirty()) {
                $banner->save();
            }
            $keep[] = $banner->id;
        }
        $existing->reject(fn (Banner $b) => in_array($b->id, $keep, true))->each(fn (Banner $b) => $b->delete());
    }

    /** @param list<array<string, mixed>> $rows */
    private static function syncPartners(array $rows): void
    {
        $keep = [];
        $order = 0;
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $logo = Fields::imageSource($row, 'logo');
            if ($name === '' || $logo === null) {
                continue;
            }
            $partner = Partner::query()->find((int) ($row['id'] ?? 0))
                ?? new Partner(['slug' => self::uniqueSlug($name), 'type' => 'principal', 'is_active' => true]);
            $logoChanged = $partner->logo !== $logo;
            $partner->fill(['name' => $name, 'logo' => $logo, 'is_official' => true, 'sort_order' => ++$order]);
            if ($logoChanged) {
                // Ukuran asli hanya berlaku untuk logo lama; logo baru tampil dengan <img> biasa (tanpa next/image).
                $partner->fill(['width' => null, 'height' => null]);
            }
            if (! $partner->exists || $partner->isDirty()) {
                $partner->save();
            }
            $keep[] = $partner->id;
        }
        Partner::query()->where('is_official', true)->whereNotIn('id', $keep)->get()
            ->each(fn (Partner $p) => $p->update(['is_official' => false]));
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'mitra';
        $slug = $base;
        for ($i = 2; Partner::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }

    /** @return list<array<string, mixed>> */
    private static function snapshotSlides(): array
    {
        return Banner::query()->where('position', 'hero')->orderBy('sort_order')->orderBy('id')->get(['id', 'image', 'alt'])
            ->map(fn (Banner $b) => ['id' => $b->id, 'image' => $b->image, 'alt' => $b->alt])->all();
    }

    /** @return list<array<string, mixed>> */
    private static function snapshotPartners(): array
    {
        return Partner::query()->where('is_official', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'logo'])
            ->map(fn (Partner $p) => ['id' => $p->id, 'name' => $p->name, 'logo' => $p->logo])->all();
    }
}
