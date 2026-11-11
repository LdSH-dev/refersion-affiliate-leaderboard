<?php

use App\Leaderboard\LeaderboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// GET /api/leaderboard[?fresh=1] (docs/SPEC.md §6). Refersion failures render themselves.
Route::get('/leaderboard', fn (Request $request, LeaderboardService $leaderboard) => $leaderboard->get(fresh: $request->boolean('fresh')))
    ->middleware('throttle:leaderboard');
