<?php

use App\Http\Controllers\TikTokLibraryController;
use Illuminate\Support\Facades\Route;

Route::get('/videos', [
    TikTokLibraryController::class,
    'apiVideos',
]);
