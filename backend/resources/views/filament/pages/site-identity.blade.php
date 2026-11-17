<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 1.5rem; display: flex; flex-wrap: wrap; gap: .75rem; align-items: center;">
            <x-filament::button type="submit">Simpan</x-filament::button>
            <span style="font-size: .875rem; opacity: .75;">Pengaturan lain (hero, statistik, dll.) tetap disunting di menu Pengaturan situs.</span>
        </div>
    </form>
</x-filament-panels::page>
