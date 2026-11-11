<?php

namespace App\Filament\Widgets;

use Filament\Widgets\AccountWidget;

/** Kartu sambutan di Dashboard admin: logo PPTI + nama pengguna + tombol keluar (pengganti AccountWidget bawaan). */
class WelcomeWidget extends AccountWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @var view-string
     */
    protected string $view = 'filament.widgets.welcome';
}
