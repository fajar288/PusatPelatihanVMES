<?php

namespace App\Observers;

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
use App\Support\CmsCache;
use Illuminate\Database\Eloquent\Model;

/** Setiap perubahan konten CMS (admin/seeder) → flush cache & revalidasi frontend dengan tag yang relevan. */
class CmsObserver
{
    public const MODELS = [
        TrainingCategory::class, TrainingPackage::class, TrainingSchedule::class, Partner::class, Testimonial::class,
        Gallery::class, Post::class, Page::class, Menu::class, Banner::class, SiteSetting::class,
    ];

    public function saved(Model $model): void
    {
        CmsCache::flush($this->tagsFor($model));
    }

    public function deleted(Model $model): void
    {
        CmsCache::flush($this->tagsFor($model));
    }

    /** @return string[] */
    private function tagsFor(Model $m): array
    {
        return match (true) {
            $m instanceof TrainingPackage => ['home', 'packages', 'package:'.$m->slug, 'schedules'],
            $m instanceof TrainingSchedule => ['home', 'schedules', 'schedule:'.$m->code, 'packages'],
            $m instanceof TrainingCategory => ['home', 'packages', 'schedules'],
            $m instanceof Post => ['home', 'posts', 'post:'.$m->slug],
            $m instanceof Page => ['pages', 'page:'.$m->slug],
            $m instanceof Menu => ['menus'],
            $m instanceof SiteSetting => ['settings', 'home'],
            default => ['home'],
        };
    }
}
