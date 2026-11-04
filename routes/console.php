<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hủy đơn mới quá hạn thanh toán (cần cron: * * * * * php artisan schedule:run)
Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();
