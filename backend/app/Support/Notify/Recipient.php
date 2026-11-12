<?php

namespace App\Support\Notify;

use App\Models\User;

/**
 * Penerima notifikasi dalam satu scope (PRD Roadmap 5 §6.1 "Recipient Scope").
 * Penerima tanpa akun (mis. trainer tanpa login) hanya bisa dikirimi email/WhatsApp.
 */
final class Recipient
{
    public const PARTICIPANT = 'participant';

    public const CORPORATE = 'corporate';

    public const TRAINER = 'trainer';

    public const ADMIN = 'admin';

    /** @param array<string, scalar|null> $vars variabel khusus penerima ini (mis. daftar peserta organisasinya) */
    public function __construct(
        public readonly string $audience,
        public readonly ?User $user = null,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?int $organizationId = null,
        public readonly array $vars = [],
        public readonly ?string $url = null,
    ) {}

    public static function user(User $user, string $audience = self::PARTICIPANT, array $vars = [], ?int $organizationId = null, ?string $url = null): self
    {
        return new self($audience, $user, $user->name, $user->email, null, $organizationId, $vars, $url);
    }

    /** Kunci unik per event: satu orang menerima satu pesan per audiens (dan per organisasi untuk audiens perusahaan). */
    public function key(): string
    {
        $who = $this->user ? 'u'.$this->user->id : 'e'.strtolower((string) ($this->email ?: $this->phone));

        return $this->audience.':'.$who.':'.($this->organizationId ?? '-');
    }

    public function displayName(): string
    {
        return $this->user?->name ?? $this->name ?? 'Bapak/Ibu';
    }
}
