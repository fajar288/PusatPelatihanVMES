<?php

namespace App\Providers;

use App\Observers\AuditObserver;
use App\Observers\CmsObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Perubahan konten CMS → flush cache API + webhook revalidasi frontend
        /** @var class-string<Model> $model */
        foreach (CmsObserver::MODELS as $model) {
            $model::observe(CmsObserver::class);
        }
        // Jejak audit otomatis untuk model operasional
        foreach (AuditObserver::MODELS as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
