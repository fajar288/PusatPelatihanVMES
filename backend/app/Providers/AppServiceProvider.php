<?php

namespace App\Providers;

use App\Filament\Support\Layout;
use App\Models\Certificate;
use App\Models\EmploymentRelationship;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\Registration;
use App\Models\TrainingRequest;
use App\Models\TrainingSchedule;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Observers\CmsObserver;
use App\Observers\EnterpriseWebhookObserver;
use App\Observers\PublicApiWebhookObserver;
use App\Observers\TrainingScheduleObserver;
use App\Services\AnalyticsService;
use App\Services\NotificationEvents;
use App\Services\SalesAnalyticsService;
use App\Support\Ai\AnthropicBatchModel;
use App\Support\Ai\BatchModel;
use App\Support\Chatbot\AnthropicChatModel;
use App\Support\Chatbot\ChatModel;
use App\Support\Push\PushSender;
use App\Support\Push\WebPushSender;
use App\Support\WhatsApp\FonnteWhatsAppSender;
use App\Support\WhatsApp\LogWhatsAppSender;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Dashboard Eksekutif: hasil analitik di-memo per request (dipakai banyak widget sekaligus).
        $this->app->scoped(AnalyticsService::class);
        $this->app->scoped(SalesAnalyticsService::class);
        // Web Push (VAPID): diganti FakePushSender di tes
        $this->app->singleton(PushSender::class, WebPushSender::class);
        // WhatsApp (Roadmap 5 §6): driver dari WHATSAPP_DRIVER (`log` bawaan, `fonnte`); diganti FakeWhatsAppSender di tes
        $this->app->singleton(WhatsAppSender::class, function ($app): WhatsAppSender {
            $driver = (string) config('notifications.whatsapp.driver', 'log');

            return match ($driver) {
                'fonnte' => $app->make(FonnteWhatsAppSender::class),
                'log' => $app->make(LogWhatsAppSender::class),
                default => tap($app->make(LogWhatsAppSender::class), fn () => Log::warning('[whatsapp] WHATSAPP_DRIVER tidak dikenal, memakai driver log.', ['driver' => $driver])),
            };
        });
        // Chatbot publik (Claude): diganti FakeChatModel di tes
        $this->app->singleton(ChatModel::class, fn () => new AnthropicChatModel);
        // Message Batches Claude (klasifikasi massal AI): diganti FakeBatchModel di tes
        $this->app->singleton(BatchModel::class, fn () => new AnthropicBatchModel);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin: Section/Grid/Fieldset/Tabs selebar form (Filament 5 tidak lagi melakukannya otomatis)
        Layout::configureDefaults();
        // Token API milik akun yang dinonaktifkan admin langsung ditolak (token juga dicabut saat dinonaktifkan).
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => $isValid && ! ($token->tokenable instanceof User && ! $token->tokenable->isActive()),
        );

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
        RateLimiter::for('registration-documents', fn (Request $request) => Limit::perMinute(10)->by('reg-docs:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('ai-feedback', fn (Request $request) => Limit::perMinute(30)->by('ai-fb:'.($request->user()?->id ?: $request->ip())));
        // Asisten Belajar: tiap pertanyaan memanggil model (beberapa detik), jadi dibatasi per menit di samping kuota harian.
        RateLimiter::for('ai-assistant', fn (Request $request) => Limit::perMinute(6)->by('ai-assist:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chatbot-feedback', fn (Request $request) => Limit::perMinute(30)->by('chat-fb:'.$request->ip()));
        // Webhook Fonnte (status pesan & device): tanpa auth → batasi per IP
        RateLimiter::for('fonnte-webhook', fn (Request $request) => Limit::perMinute(120)->by('fonnte-wh:'.$request->ip()));

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
        // API enterprise (Roadmap 5E): webhook ke klien yang terikat organisasi
        foreach ([TrainingRequest::class, Enrollment::class, EmploymentRelationship::class, Invoice::class, Certificate::class] as $model) {
            $model::observe(EnterpriseWebhookObserver::class);
        }
    }
}
