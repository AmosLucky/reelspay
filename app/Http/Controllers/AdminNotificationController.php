<?php

namespace App\Http\Controllers;

use App\Models\PushNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use RuntimeException;
use Throwable;

class AdminNotificationController extends Controller
{
    public function __construct(private Messaging $messaging) {}

    public function index(): View
    {
        $notifications = PushNotification::query()
            ->latest('created_at')
            ->paginate(15);

        return view('admin.notifications.index', compact('notifications'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->storePublicly(
                'notification-images',
                'public'
            );

            if ($imagePath === false) {
                throw new RuntimeException('Could not save the notification image.');
            }
        }

        try {
            $pushNotification = PushNotification::create([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'topic' => 'all',
                'image_path' => $imagePath,
                'status' => 'sending',
            ]);
        } catch (Throwable $exception) {
            $this->deleteStoredImage($imagePath);

            throw $exception;
        }

        try {
            $imageUrl = $imagePath !== null
                ? asset(Storage::disk('public')->url($imagePath))
                : null;
            $message = CloudMessage::withTarget('topic', 'all')
                ->withNotification(Notification::create(
                    $pushNotification->title,
                    $pushNotification->body,
                    $imageUrl
                ));
            $result = $this->messaging->send($message);

            $pushNotification->update([
                'status' => 'sent',
                'firebase_message_id' => is_string($result['name'] ?? null)
                    ? $result['name']
                    : null,
                'sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $pushNotification->update([
                'status' => 'failed',
                'error_message' => 'Firebase could not deliver this notification. Check the application logs.',
            ]);

            return redirect()
                ->route('admin.notifications.index')
                ->with(
                    'error',
                    'The notification was saved but could not be sent. Check its status and the application logs.'
                );
        }

        return redirect()
            ->route('admin.notifications.index')
            ->with('status', 'Notification sent to the all topic.');
    }

    public function destroy(PushNotification $pushNotification): RedirectResponse
    {
        $this->deleteStoredImage($pushNotification->image_path);
        $pushNotification->delete();

        return redirect()
            ->route('admin.notifications.index')
            ->with('status', 'Notification and its image were deleted.');
    }

    private function deleteStoredImage(?string $path): void
    {
        if (
            $path === null
            || ! str_starts_with($path, 'notification-images/')
            || in_array('..', explode('/', $path), true)
        ) {
            return;
        }

        $disk = Storage::disk('public');

        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new RuntimeException('Could not delete notification image.');
        }
    }
}
