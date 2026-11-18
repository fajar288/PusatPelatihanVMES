<?php

namespace Tests;

use App\Support\PrivateFiles;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Berkas privat (bukti bayar, tugas, logbook, materi LMS, dokumen, foto) tidak boleh tertulis ke storage asli
        // selama tes. Tes yang perlu disk lokal sungguhan (URL bertanda tangan) mengganti root lalu Storage::forgetDisk.
        Storage::fake(PrivateFiles::diskName());
    }
}
