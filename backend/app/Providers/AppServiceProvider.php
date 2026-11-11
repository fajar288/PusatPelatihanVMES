<?php

namespace App\Providers;

use App\Models\TrainingSchedule;
use App\Observers\AuditObserver;
use App\Observers\CmsObserver;
use App\Observers\TrainingScheduleObserver;
use App\Services\AnalyticsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Dashboard Eksekutif: hasil analitik di-memo per request (dipakai banyak widget sekaligus).
        $this->app->scoped(AnalyticsService::class);
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
        TrainingSchedule::observe(TrainingScheduleObserver::class); // riwayat perubahan + notifikasi peserta
        // Jejak audit otomatis untuk model operasional
        foreach (AuditObserver::MODELS as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
