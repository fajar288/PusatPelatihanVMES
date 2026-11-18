<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen pendukung pendaftaran: `documents` tetap berisi daftar path (disk privat `local`), `document_names` memetakan
 * path → nama berkas asli agar admin dan pendaftar melihat nama yang dikenali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->json('document_names')->nullable()->after('documents')->comment('path dokumen => nama berkas asli');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn('document_names');
        });
    }
};
