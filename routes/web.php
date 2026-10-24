
<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\TikTokController;
use App\Http\Controllers\TikTokLibraryController;
use Illuminate\Support\Facades\Route;


use App\Services\FirestoreService;

Route::get('/test-firestore', function (FirestoreService $firestore) {
    try {
        return response()->json([
            'success' => true,
            'message' => 'Firestore test completed.',
            'result' => $firestore->testConnection(),
        ]);
    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
});


Route::get('/admin/login', [AdminAuthController::class, 'create'])
    ->middleware('guest')
    ->name('login');
Route::post('/admin/login', [AdminAuthController::class, 'store'])
    ->middleware(['guest', 'throttle:6,1'])
    ->name('login.store');
Route::post('/admin/logout', [AdminAuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->prefix('admin/tiktok-library')->name('tiktok-library.')->group(function () {
    Route::get('/', [TikTokLibraryController::class, 'index'])->name('index');
    Route::post('/import', [TikTokLibraryController::class, 'import'])->name('import');
    Route::get('/{video}/edit', [TikTokLibraryController::class, 'edit'])->name('edit');
    Route::put('/{video}', [TikTokLibraryController::class, 'update'])->name('update');
    Route::delete('/{video}', [TikTokLibraryController::class, 'destroy'])->name('destroy');
});

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('home');
    Route::get('/notifications', [AdminNotificationController::class, 'index'])
        ->name('notifications.index');
    Route::post('/notifications', [AdminNotificationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('notifications.store');
    Route::delete('/notifications/{pushNotification}', [AdminNotificationController::class, 'destroy'])
        ->name('notifications.destroy');
});

Route::get('/', [TikTokController::class, 'page']);

Route::get('/tiktok-page', [TikTokController::class, 'page']);

Route::get('/tiktok-download', [TikTokController::class, 'download']);

Route::get('/tiktok-file', [TikTokController::class, 'file']);
