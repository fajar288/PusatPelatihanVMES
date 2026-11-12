<?php

namespace App\Providers;

use App\Models\Certificate;
use App\Models\Lesson;
use App\Models\Registration;
use App\Models\TrainingSchedule;
use App\Observers\AuditObserver;
use App\Observers\CmsObserver;
use App\Observers\PublicApiWebhookObserver;
use App\Observers\TrainingScheduleObserver;
use App\Services\AnalyticsService;
use App\Services\NotificationEvents;
use App\Support\Chatbot\AnthropicChatModel;
use App\Support\Chatbot\ChatModel;
use App\Support\Push\PushSender;
use App\Support\Push\WebPushSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Web Push (VAPID) — diganti FakePushSender di tes
        $this->app->singleton(PushSender::class, WebPushSender::class);
        // Chatbot publik (Claude) — diganti FakeChatModel di tes
        $this->app->singleton(ChatModel::class, fn () => new AnthropicChatModel);
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
        // Limiter bernama per pengguna (throttle:N,1 tanpa nama berbagi satu penghitung lintas route)
        RateLimiter::for('push-subscribe', fn (Request $request) => Limit::perMinute(20)->by('push-sub:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('push-test', fn (Request $request) => Limit::perMinute(5)->by('push-test:'.($request->user()?->id ?: $request->ip())));
        // Chatbot publik: batas per IP per menit & per hari (biaya API per token)
        RateLimiter::for('chatbot', fn (Request $request) => [
            Limit::perMinute(max(1, (int) config('chatbot.per_ip_per_minute')))->by('chat-m:'.$request->ip()),
            Limit::perDay(max(1, (int) config('chatbot.per_ip_per_day')))->by('chat-d:'.$request->ip()),
        ]);
        RateLimiter::for('verify-resend', fn (Request $request) => Limit::perMinute(3)->by('verify-resend:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('payment-proof', fn (Request $request) => Limit::perMinute(10)->by('payment-proof:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chatbot-feedback', fn (Request $request) => Limit::perMinute(30)->by('chat-fb:'.$request->ip()));

        // Notifikasi "materi baru" saat lesson terbit / mulai tersedia (PRD §5.12)
        Lesson::saved(function (Lesson $lesson): void {
            if ($lesson->is_published && ($lesson->wasRecentlyCreated || $lesson->wasChanged(['is_published', 'available_from']))) {
                app(NotificationEvents::class)->lessonPublished($lesson);
            }
        });
        // API Publik: webhook ke klien untuk pendaftaran yang dibuat lewat API (status, sertifikat)
        foreach ([Registration::class, Certificate::class] as $model) {
            $model::observe(PublicApiWebhookObserver::class);
        }
    }
}
