<?php

namespace App\Enums;

/** Status pendaftaran (PRD §5.5) beserta transisi yang diizinkan. */
enum RegistrationStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case WaitingPayment = 'waiting_payment';
    case PaymentVerification = 'payment_verification';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu diproses',
            self::UnderReview => 'Sedang ditinjau',
            self::WaitingPayment => 'Menunggu pembayaran',
            self::PaymentVerification => 'Verifikasi pembayaran',
            self::Confirmed => 'Terkonfirmasi',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** @return self[] */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::UnderReview, self::WaitingPayment, self::Rejected, self::Cancelled],
            self::UnderReview => [self::WaitingPayment, self::Rejected, self::Cancelled],
            self::WaitingPayment => [self::PaymentVerification, self::Rejected, self::Cancelled],
            self::PaymentVerification => [self::Confirmed, self::WaitingPayment, self::Rejected, self::Cancelled],
            self::Confirmed => [self::Cancelled],
            self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled], true);
    }

    /** Pendaftar boleh membatalkan sendiri selama belum terkonfirmasi/final. */
    public function cancellableByOwner(): bool
    {
        return ! $this->isFinal() && $this !== self::Confirmed;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(array_map(fn (self $s) => $s->value, self::cases()), array_map(fn (self $s) => $s->label(), self::cases()));
    }
}
