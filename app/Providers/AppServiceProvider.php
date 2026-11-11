<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A fresh board can cost dozens of Refersion calls against a 300 reads/min budget
        // shared by the whole account, so recomputes are throttled. Cached reads are cheap.
        RateLimiter::for('leaderboard', fn (Request $request) => $request->boolean('fresh')
            ? Limit::perMinute(6)->by('fresh|'.$request->ip())
            : Limit::perMinute(120)->by('read|'.$request->ip()));
    }
}
