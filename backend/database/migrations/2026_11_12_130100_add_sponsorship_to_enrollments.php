<?php

use App\Support\Enterprise\SponsorshipBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 5 tahap 5A — Enrollment Sponsorship (PRD Roadmap 5 §4.4, §20.19 R1/R2).
 * - enrollments.type self|corporate + sponsor_organization_id (+ sponsor_unit_id untuk batas departemen).
 * - registrations.organization_id: pendaftaran kolektif ditautkan ke organisasi.
 * - Backfill: enrollment individu → SELF; enrollment dari pendaftaran kolektif → CORPORATE dengan organisasi
 *   hasil normalisasi nama perusahaan (needs_review = true agar diperiksa admin).
 * - Constraint DB R1/R2: corporate ⇔ sponsor terisi (CHECK di PostgreSQL/MySQL, trigger di SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->string('type', 10)->default('self')->after('member_id')->comment('self|corporate');
            $table->foreignId('sponsor_organization_id')->nullable()->after('type')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('sponsor_unit_id')->nullable()->after('sponsor_organization_id')->constrained('organization_units')->nullOnDelete();
            $table->index(['sponsor_organization_id', 'status']);
        });

        Schema::table('registrations', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('member_id')->constrained()->nullOnDelete();
        });

        SponsorshipBackfill::run();
        $this->addSponsorConstraint();
    }

    public function down(): void
    {
        $this->dropSponsorConstraint();
        Schema::table('registrations', fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_id'));
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropIndex(['sponsor_organization_id', 'status']);
            $table->dropConstrainedForeignId('sponsor_unit_id');
            $table->dropConstrainedForeignId('sponsor_organization_id');
            $table->dropColumn('type');
        });
    }

    private function addSponsorConstraint(): void
    {
        $check = "(type = 'self' AND sponsor_organization_id IS NULL) OR (type = 'corporate' AND sponsor_organization_id IS NOT NULL)";

        match (DB::getDriverName()) {
            'sqlite' => collect(['INSERT', 'UPDATE'])->each(fn (string $op) => DB::unprepared(
                'CREATE TRIGGER enrollments_sponsor_rule_'.strtolower($op)." BEFORE {$op} ON enrollments
                 WHEN NOT ((NEW.type = 'self' AND NEW.sponsor_organization_id IS NULL) OR (NEW.type = 'corporate' AND NEW.sponsor_organization_id IS NOT NULL))
                 BEGIN SELECT RAISE(ABORT, 'enrollments_sponsor_rule: CORPORATE wajib sponsor, SELF tanpa sponsor'); END;"
            )),
            default => DB::statement("ALTER TABLE enrollments ADD CONSTRAINT enrollments_sponsor_rule CHECK ({$check})"),
        };
    }

    private function dropSponsorConstraint(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => collect(['insert', 'update'])->each(fn (string $op) => DB::unprepared("DROP TRIGGER IF EXISTS enrollments_sponsor_rule_{$op}")),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE enrollments DROP CHECK enrollments_sponsor_rule'),
            default => DB::statement('ALTER TABLE enrollments DROP CONSTRAINT IF EXISTS enrollments_sponsor_rule'),
        };
    }
};
