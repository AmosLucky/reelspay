
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TikTokController;


use App\Http\Controllers\TikTokLibraryController;

Route::get('/admin/tiktok-library', [
    TikTokLibraryController::class,
    'index',
])->name('tiktok-library.index');

Route::post('/admin/tiktok-library/import', [
    TikTokLibraryController::class,
    'import',
])->name('tiktok-library.import');


Route::get('/', [TikTokController::class, 'page']);

Route::get('/tiktok-page', [TikTokController::class, 'page']);

Route::get('/tiktok-download', [TikTokController::class, 'download']);

Route::get('/tiktok-file', [TikTokController::class, 'file']);
