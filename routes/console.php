<?php

use App\Enums\UserRole;
use App\Jobs\SyncInboxForUser;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    User::query()
        ->where('role', UserRole::Staff)
        ->pluck('id')
        ->each(function (int $userId): void {
            SyncInboxForUser::dispatch($userId);
        });
})->everyMinute();
