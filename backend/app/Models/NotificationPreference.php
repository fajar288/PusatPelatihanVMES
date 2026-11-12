<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Preferensi notifikasi pengguna per kategori (email, push & WhatsApp). Notifikasi aplikasi selalu aktif. */
class NotificationPreference extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['mail' => 'boolean', 'push' => 'boolean', 'whatsapp' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
