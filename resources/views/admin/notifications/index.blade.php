@extends('admin.layout')

@section('title', 'Send notification')

@section('content')
    @if (session('status'))
        <div class="mb-5 rounded-lg bg-emerald-100 px-4 py-3 text-sm text-emerald-900" role="status">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-5 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900" role="alert">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-5 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900" role="alert">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section class="rounded-xl bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold">Compose push notification</h2>
        <p class="mt-1 text-sm text-slate-600">This broadcasts to devices subscribed to the FCM topic <code>all</code>.</p>

        <form class="mt-6 grid max-w-3xl gap-5" method="POST"
            action="{{ route('admin.notifications.store') }}" enctype="multipart/form-data">
            @csrf
            <div>
                <label class="mb-2 block text-sm font-medium" for="title">Notification title</label>
                <input class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                    id="title" name="title" value="{{ old('title') }}" maxlength="120" required>
            </div>
            <div>
                <label class="mb-2 block text-sm font-medium" for="body">Message</label>
                <textarea class="min-h-32 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                    id="body" name="body" maxlength="2000" required>{{ old('body') }}</textarea>
            </div>
            <div>
                <label class="mb-2 block text-sm font-medium" for="image">Image (optional, up to 5 MB)</label>
                <input class="block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2"
                    id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp">
                <p class="mt-1 text-xs text-slate-500">The image URL must be publicly reachable by Firebase devices.</p>
            </div>
            <div>
                <button class="rounded-lg bg-indigo-700 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-600"
                    type="submit">Send to all devices</button>
            </div>
        </form>
    </section>

    <section class="mt-8 rounded-xl bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold">Notification history</h2>
        <div class="mt-5 grid gap-4">
            @forelse ($notifications as $notification)
                <article class="flex flex-col gap-4 border-t border-slate-100 pt-5 sm:flex-row">
                    @if ($notification->image_path)
                        <img class="h-24 w-24 rounded-lg object-cover"
                            src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($notification->image_path) }}"
                            alt="Notification image">
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">{{ $notification->title }}</h3>
                            <span @class([
                                'rounded-full px-3 py-1 text-xs font-semibold',
                                'bg-emerald-100 text-emerald-800' => $notification->status === 'sent',
                                'bg-red-100 text-red-800' => $notification->status === 'failed',
                                'bg-amber-100 text-amber-800' => $notification->status === 'sending',
                            ])>{{ ucfirst($notification->status) }}</span>
                        </div>
                        <p class="mt-2 whitespace-pre-wrap text-sm text-slate-700">{{ $notification->body }}</p>
                        <p class="mt-2 text-xs text-slate-500">
                            {{ $notification->sent_at?->format('M j, Y g:i A') ?? $notification->created_at->format('M j, Y g:i A') }}
                            · Topic: {{ $notification->topic }}
                        </p>
                        @if ($notification->error_message)
                            <p class="mt-2 text-sm text-red-700">{{ $notification->error_message }}</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('admin.notifications.destroy', $notification) }}"
                        onsubmit="return confirm('Delete this notification and its image?')">
                        @csrf
                        @method('DELETE')
                        <button class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                            type="submit">Delete</button>
                    </form>
                </article>
            @empty
                <p class="border-t border-slate-100 pt-5 text-sm text-slate-500">No notifications yet.</p>
            @endforelse
        </div>
        <div class="mt-6">{{ $notifications->links() }}</div>
    </section>
@endsection
