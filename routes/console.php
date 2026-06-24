<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('memberships:expire')->daily();

Schedule::command('collaborations:expire')->dailyAt('00:10');

Schedule::command('app:update-reminder-notifications')->hourly();

Schedule::command('memberships:send-expiry-reminders')->dailyAt('11:25')->timezone('Asia/Kolkata');

Schedule::command('memberships:send-upcoming-expiry-reminders')->dailyAt('11:25')->timezone('Asia/Kolkata');

Schedule::command('memberships:send-circle-expiry-reminders')->dailyAt('11:25')->timezone('Asia/Kolkata');

Schedule::command('app:send-daily-engagement-reminders')->hourly();
