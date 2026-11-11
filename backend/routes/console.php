<?php

use App\Models\ApiRequestLog;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pangkas log permintaan API Publik & riwayat webhook lama (API_LOG_RETENTION_DAYS, default 90 hari)
Schedule::command('model:prune', ['--model' => [ApiRequestLog::class, WebhookDelivery::class]])->daily();
