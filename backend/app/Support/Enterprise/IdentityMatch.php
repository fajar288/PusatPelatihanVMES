<?php

namespace App\Support\Enterprise;

use App\Models\EmploymentRelationship;
use App\Models\Member;
use App\Models\User;

/** Hasil pencocokan identitas peserta (PRD Roadmap 5 §20.5). */
final class IdentityMatch
{
    /** Email terverifikasi / nomor member cocok → pakai Member yang ada. */
    public const USE_EXISTING = 'use_existing';

    /** Nomor HP / email belum terverifikasi cocok → kandidat, perlu konfirmasi. */
    public const CONFIRM = 'confirm';

    /** Employee ID cocok di organisasi → tawarkan employment yang ada (perlu konfirmasi). */
    public const EXISTING_EMPLOYMENT = 'existing_employment';

    /** Tidak ditemukan → undangan / member baru sesuai kebijakan (tidak dibuat otomatis). */
    public const CREATE_NEW = 'create_new';

    /** Sudah terdaftar di batch yang sama → tolak. */
    public const DUPLICATE = 'duplicate_enrollment';

    /** Data meragukan (konflik / tidak lengkap / nomor member tak dikenal) → review queue. */
    public const REVIEW = 'review';

    /**
     * @param  list<array{member_id:int, member_number:string, name:string, matched_by:string}>  $candidates
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?Member $member = null,
        public readonly ?User $user = null,
        public readonly array $candidates = [],
        public readonly ?string $reason = null,
        public readonly ?EmploymentRelationship $employment = null,
    ) {}

    /** Boleh langsung dipakai tanpa konfirmasi manusia. */
    public function isConfident(): bool
    {
        return $this->outcome === self::USE_EXISTING;
    }
}
