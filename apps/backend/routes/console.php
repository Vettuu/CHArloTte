<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\ReportRetagMessages;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('report:retag', function () {
    $this->call(ReportRetagMessages::class);
})->purpose('Ricalcola i topic nei log chat_messages');
