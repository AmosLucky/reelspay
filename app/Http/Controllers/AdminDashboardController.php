<?php

namespace App\Http\Controllers;

use App\Models\PushNotification;
use App\Models\Video;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.home', [
            'videoCount' => Video::count(),
            'sentNotificationCount' => PushNotification::where('status', 'sent')->count(),
            'failedNotificationCount' => PushNotification::where('status', 'failed')->count(),
            'recentNotifications' => PushNotification::query()
                ->latest('created_at')
                ->limit(5)
                ->get(),
        ]);
    }
}
