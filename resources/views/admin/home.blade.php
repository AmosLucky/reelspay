@extends('admin.layout')

@section('title', 'Home')

@section('content')
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
        <section class="rounded-xl bg-white p-6 shadow-sm">
            <p class="text-sm text-slate-500">Videos</p>
            <p class="mt-2 text-3xl font-semibold">{{ number_format($videoCount) }}</p>
            <a class="mt-4 inline-block text-sm font-medium text-indigo-700 hover:underline"
                href="{{ route('tiktok-library.index') }}">Manage videos</a>
        </section>
        <section class="rounded-xl bg-white p-6 shadow-sm">
            <p class="text-sm text-slate-500">Notifications sent</p>
            <p class="mt-2 text-3xl font-semibold">{{ number_format($sentNotificationCount) }}</p>
            <a class="mt-4 inline-block text-sm font-medium text-indigo-700 hover:underline"
                href="{{ route('admin.notifications.index') }}">Send notification</a>
        </section>
        <section class="rounded-xl bg-white p-6 shadow-sm">
            <p class="text-sm text-slate-500">Notifications failed</p>
            <p class="mt-2 text-3xl font-semibold">{{ number_format($failedNotificationCount) }}</p>
        </section>
    </div>

    <section class="mt-8 rounded-xl bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">Recent notifications</h2>
            <a class="text-sm font-medium text-indigo-700 hover:underline"
                href="{{ route('admin.notifications.index') }}">View history</a>
        </div>
        @forelse ($recentNotifications as $notification)
            <div class="mt-4 flex items-start justify-between gap-4 border-t border-slate-100 pt-4">
                <div>
                    <p class="font-medium">{{ $notification->title }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $notification->body }}</p>
                </div>
                <span @class([
                    'rounded-full px-3 py-1 text-xs font-semibold',
                    'bg-emerald-100 text-emerald-800' => $notification->status === 'sent',
                    'bg-red-100 text-red-800' => $notification->status === 'failed',
                    'bg-amber-100 text-amber-800' => $notification->status === 'sending',
                ])>{{ ucfirst($notification->status) }}</span>
            </div>
        @empty
            <p class="mt-4 text-sm text-slate-500">No notifications have been sent yet.</p>
        @endforelse
    </section>
@endsection
