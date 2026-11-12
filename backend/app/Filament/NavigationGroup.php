<?php

namespace App\Filament;

use Filament\Support\Contracts\Collapsible;
use Filament\Support\Contracts\HasLabel;

/**
 * Grup menu sidebar panel admin. Urutan case = urutan grup di sidebar
 * (didaftarkan lewat AdminPanelProvider::navigationGroups(NavigationGroup::class)).
 * Menu tanpa grup (Dashboard) selalu tampil paling atas.
 */
enum NavigationGroup implements Collapsible, HasLabel
{
    case Analytics;
    case LandingPage;
    case TmsProgram;
    case TmsRegistration;
    case Corporate;
    case TmsDelivery;
    case Lms;
    case Certificates;
    case Notifications;
    case System;

    public function getLabel(): string
    {
        return match ($this) {
            self::Analytics => 'Laporan & Analitik',
            self::LandingPage => 'Landing Page (Website)',
            self::TmsProgram => 'TMS: Program & Kelas',
            self::TmsRegistration => 'TMS: Pendaftaran & Keuangan',
            self::Corporate => 'Korporat (Enterprise)',
            self::TmsDelivery => 'TMS: Pelaksanaan & Penilaian',
            self::Lms => 'LMS (E-learning)',
            self::Certificates => 'Sertifikat & Alumni',
            self::Notifications => 'Notifikasi',
            self::System => 'Sistem & Integrasi',
        };
    }

    public function isCollapsible(): bool
    {
        return true;
    }

    /** Grup teknis jarang dibuka → diciutkan secara bawaan agar sidebar tetap ringkas. */
    public function isCollapsed(): bool
    {
        return in_array($this, [self::Notifications, self::System], true);
    }
}
