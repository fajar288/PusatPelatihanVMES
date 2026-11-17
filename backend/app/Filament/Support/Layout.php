<?php

namespace App\Filament\Support;

use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;

/**
 * Tata letak form admin yang responsif.
 *
 * Filament 5 tidak lagi membuat Section, Grid, Fieldset, dan Tabs selebar form, dan `columns(6)`
 * berlaku penuh sejak layar `lg` (1024 px) walau area konten masih sempit karena sidebar.
 * Akibatnya section tampil berdampingan dan field menyempit sampai teks pecah per huruf.
 * Helper ini memberi grid bertingkat: satu kolom di layar sempit, dua kolom mulai `md`,
 * dan jumlah kolom penuh baru di `xl`.
 */
final class Layout
{
    /** Dipanggil sekali saat boot: komponen tata letak selalu memakai lebar penuh induknya. */
    public static function configureDefaults(): void
    {
        Section::configureUsing(fn (Section $component) => $component->columnSpanFull());
        Fieldset::configureUsing(fn (Fieldset $component) => $component->columnSpanFull());
        Grid::configureUsing(fn (Grid $component) => $component->columnSpanFull());
        Tabs::configureUsing(fn (Tabs $component) => $component->columnSpanFull());
    }

    /**
     * Jumlah kolom grid: 1 di layar sempit, `$md` mulai 768 px, `$xl` mulai 1280 px.
     *
     * @return array<string, int>
     */
    public static function cols(int $xl, int $md = 2): array
    {
        $md = min($md, $xl);

        return ['default' => 1, 'md' => $md, 'lg' => $md, 'xl' => $xl];
    }

    /**
     * Lebar field dalam grid dari cols(): `$xl` kolom di layar lebar, `$md` (bawaan: sebanyak
     * mungkin sampai 2) di layar sedang, selalu satu kolom penuh di layar sempit.
     *
     * @return array<string, int>
     */
    public static function span(int $xl, ?int $md = null): array
    {
        $md ??= min($xl, 2);

        return ['default' => 1, 'md' => $md, 'lg' => $md, 'xl' => $xl];
    }
}
