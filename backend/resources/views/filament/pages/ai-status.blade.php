<x-filament-panels::page>
    {{ $this->table }}

    <x-filament::section heading="Cara menyalakan" collapsible collapsed>
        <p class="ai-status-note">
            Fitur AI hanya bisa dinyalakan lewat file <code>.env</code> backend: <code>AI_ENABLED=true</code> ditambah flag use case
            (misalnya <code>AI_EARLY_WARNING_ENABLED=true</code>). Use case yang memakai Claude juga butuh <code>ANTHROPIC_API_KEY</code>.
            Sebelum dinyalakan, jalankan <code>php artisan ai:readiness</code> dan <code>php artisan ai:evaluate &lt;use case&gt;</code>,
            lalu minta persetujuan pemilik proyek. Selama data belum cukup, hasil evaluasi hanya membuktikan pipeline berjalan.
        </p>
    </x-filament::section>

    <style>
        .ai-status-note { font-size: .875rem; line-height: 1.5rem; color: var(--gray-600); }
        .dark .ai-status-note { color: var(--gray-400); }
        .ai-status-note code { font-size: .8125rem; }
    </style>
</x-filament-panels::page>
