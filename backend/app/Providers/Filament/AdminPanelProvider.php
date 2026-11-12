<?php

namespace App\Providers\Filament;

use App\Filament\NavigationGroup;
use App\Filament\Widgets\WelcomeWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Panel admin CMS (/admin) — PRD §5.2. Akses dibatasi User::canAccessPanel (peran admin). */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('PPTI VMES ITMS')
            // Logo PPTI (PNG transparen dari public/images/logo.jpeg); varian gelap: tulisan hijau tua → putih.
            ->brandLogo(fn () => asset('images/logo-admin.png'))
            ->darkModeBrandLogo(fn () => asset('images/logo-admin-dark.png'))
            ->brandLogoHeight(fn () => request()->routeIs('filament.admin.auth.*') ? '4.5rem' : '2.75rem')
            ->favicon(fn () => asset('images/favicon-admin.png'))
            ->navigationGroups(NavigationGroup::class)
            // Notification Engine (Roadmap 5 §6): notifikasi audiens admin ("Dashboard") tampil di lonceng panel
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->colors([
                'primary' => Color::hex('#004B23'), // hijau tema pusatpelatihan.id (--p)
                'success' => Color::hex('#6BB218'), // --l
                'warning' => Color::hex('#FA5000'), // --o
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                WelcomeWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
