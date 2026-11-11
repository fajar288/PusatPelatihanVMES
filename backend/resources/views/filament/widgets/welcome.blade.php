@php
    use Filament\Support\Icons\Heroicon;
    use Filament\View\PanelsIconAlias;

    $user = filament()->auth()->user();
@endphp

{{-- Utilitas Tailwind kustom tidak tersedia di CSS panel terkompilasi → gaya inline + kelas bawaan Filament (fi-logo-light/dark, fi-account-widget-*). --}}
<x-filament-widgets::widget class="fi-account-widget">
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:1rem 1.5rem;width:100%">
            <img src="{{ asset('images/logo-admin.png') }}" alt="PPTI - Pusat Pelatihan Teknologi Industri" class="fi-logo fi-logo-light" style="height:4rem;width:auto" />
            <img src="{{ asset('images/logo-admin-dark.png') }}" alt="PPTI - Pusat Pelatihan Teknologi Industri" class="fi-logo fi-logo-dark" style="height:4rem;width:auto" />

            <div class="fi-account-widget-main" style="min-width:14rem">
                <h2 class="fi-account-widget-heading">Selamat datang, {{ filament()->getUserName($user) }}</h2>
                <p class="fi-account-widget-user-name">
                    Panel admin PPTI VMES ITMS. Kelola website, program &amp; kelas, pendaftaran, LMS, dan sertifikat lewat menu di samping.
                </p>
            </div>

            <form action="{{ filament()->getLogoutUrl() }}" method="post" class="fi-account-widget-logout-form">
                @csrf

                <x-filament::button
                    color="gray"
                    :icon="Heroicon::ArrowLeftEndOnRectangle"
                    :icon-alias="PanelsIconAlias::WIDGETS_ACCOUNT_LOGOUT_BUTTON"
                    labeled-from="sm"
                    tag="button"
                    type="submit"
                >
                    {{ __('filament-panels::widgets/account-widget.actions.logout.label') }}
                </x-filament::button>
            </form>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
