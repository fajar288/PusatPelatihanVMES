<?php

namespace App\Filament\Support;

/** Izin panel admin grup AI: ai.review (tinjau insight, lihat pemakaian) dan ai.manage (jeda/lanjutkan use case). */
final class AiAccess
{
    public static function canReview(): bool
    {
        $u = auth()->user();

        return (bool) ($u?->hasPermission('ai.review') || $u?->hasPermission('ai.manage'));
    }

    public static function canDecide(): bool
    {
        return (bool) auth()->user()?->hasPermission('ai.review');
    }

    public static function canManage(): bool
    {
        return (bool) auth()->user()?->hasPermission('ai.manage');
    }
}
