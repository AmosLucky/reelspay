<?php

use App\Http\Controllers\TikTokLibraryController;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AutomaticPushNotificationController;

Route::get(
    '/automatic-puzzle-notification',
    [AutomaticPushNotificationController::class, 'send']
);


Route::get('/videos', [
    TikTokLibraryController::class,
    'apiVideos',
]);
