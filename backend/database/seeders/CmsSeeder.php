<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Gallery;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Partner;
use App\Models\Post;
use App\Models\SiteSetting;
use App\Models\Testimonial;
use App\Models\TrainingCategory;
use App\Models\TrainingPackage;
use App\Models\TrainingSchedule;
use Illuminate\Database\Seeder;

/**
 * Seed awal dari konten contoh frontend (database/seeders/data/*.json, dihasilkan oleh
 * frontend/scripts/export-seed.mjs). Idempoten: updateOrCreate berdasarkan slug/kode/key.
 */
class CmsSeeder extends Seeder
{
    public function run(): void
    {
        $this->categories();
        $this->packages();
        $this->schedules();
        $this->partners();
        $this->testimonials();
        $this->galleries();
        $this->posts();
        $this->pages();
        $this->menus();
        $this->banners();
        $this->settings();
    }

    /** @return array<int, array<string, mixed>>|array<string, mixed> */
    private function data(string $name): array
    {
        $path = __DIR__."/data/{$name}.json";

        return file_exists($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    private function categories(): void
    {
        $rows = $this->data('categories');
        foreach ($rows as $row) {
            if ($row['parent_slug'] === null) {
                TrainingCategory::query()->updateOrCreate(['slug' => $row['slug']], collect($row)->except('parent_slug')->all());
            }
        }
        foreach ($rows as $row) {
            if ($row['parent_slug'] !== null) {
                $parent = TrainingCategory::query()->where('slug', $row['parent_slug'])->first();
                TrainingCategory::query()->updateOrCreate(['slug' => $row['slug']], collect($row)->except('parent_slug')->put('parent_id', $parent?->id)->all());
            }
        }
    }

    private function packages(): void
    {
        foreach ($this->data('packages') as $row) {
            $cat = TrainingCategory::query()->where('slug', $row['category_slug'])->first();
            TrainingPackage::query()->updateOrCreate(['slug' => $row['slug']], collect($row)->except('category_slug')->put('category_id', $cat?->id)->all());
        }
    }

    private function schedules(): void
    {
        foreach ($this->data('schedules') as $row) {
            $pkg = TrainingPackage::query()->where('slug', $row['package_slug'])->first();
            if (! $pkg) {
                // paket belum ada di katalog (mis. CCNP, Linux) → buat paket draft agar jadwal tetap tampil
                $pkg = TrainingPackage::query()->create([
                    'slug' => $row['package_slug'], 'title' => $row['title'], 'excerpt' => null,
                    'duration_days' => 1, 'mode' => $row['mode'], 'price' => $row['price'], 'status' => 'draft',
                ]);
            }
            TrainingSchedule::query()->updateOrCreate(['code' => $row['code']], collect($row)->except('package_slug')->put('package_id', $pkg->id)->all());
        }
    }

    private function partners(): void
    {
        foreach ($this->data('partners') as $row) {
            Partner::query()->updateOrCreate(['slug' => $row['slug']], $row);
        }
    }

    private function testimonials(): void
    {
        foreach ($this->data('testimonials') as $row) {
            Testimonial::query()->updateOrCreate(['name' => $row['name'], 'company' => $row['company']], $row);
        }
    }

    private function galleries(): void
    {
        foreach ($this->data('galleries') as $row) {
            Gallery::query()->updateOrCreate(['image' => $row['image']], $row);
        }
    }

    private function posts(): void
    {
        foreach ($this->data('posts') as $row) {
            Post::query()->updateOrCreate(['slug' => $row['slug']], $row);
        }
    }

    private function pages(): void
    {
        foreach ($this->data('pages') as $row) {
            Page::query()->updateOrCreate(['slug' => $row['slug']], $row + ['published_at' => now()]);
        }
    }

    private function menus(): void
    {
        foreach ($this->data('menus') as $row) {
            Menu::query()->updateOrCreate(['location' => $row['location'], 'label' => $row['label']], $row);
        }
    }

    private function banners(): void
    {
        foreach ($this->data('banners') as $row) {
            Banner::query()->updateOrCreate(['position' => $row['position'], 'image' => $row['image']], $row);
        }
    }

    private function settings(): void
    {
        foreach ($this->data('settings') as $key => $value) {
            SiteSetting::set($key, $value, 'site');
        }
    }
}
