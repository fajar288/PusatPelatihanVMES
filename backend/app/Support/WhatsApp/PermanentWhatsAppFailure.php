<?php

namespace App\Support\WhatsApp;

use RuntimeException;

/**
 * Kegagalan WhatsApp yang tidak akan pulih dengan mencoba ulang (mis. token salah, nomor tujuan tidak valid, kuota habis).
 * NotificationDispatcher langsung menandai pengiriman "failed" tanpa menjadwalkan retry. Driver mana pun boleh melemparnya.
 */
class PermanentWhatsAppFailure extends RuntimeException {}
