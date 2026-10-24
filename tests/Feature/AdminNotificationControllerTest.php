<?php

use App\Models\PushNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;

uses(LazilyRefreshDatabase::class);

it('requires authentication to manage push notifications', function () {
    $this->get(route('admin.notifications.index'))
        ->assertRedirect(route('login'));
});

it('shows the admin home with sidebar destinations', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.home'))
        ->assertOk()
        ->assertSee('Home')
        ->assertSee('Send notification')
        ->assertSee('Videos')
        ->assertSee('Notifications sent');
});

it('shows the notification composer and saved notification history', function () {
    Storage::fake('public');
    $admin = User::factory()->create();
    PushNotification::create([
        'title' => 'Previously sent',
        'body' => 'Saved message body',
        'topic' => 'all',
        'image_path' => 'notification-images/saved.png',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.notifications.index'))
        ->assertOk()
        ->assertSee('Compose push notification')
        ->assertSee('Previously sent')
        ->assertSee('Saved message body')
        ->assertSee('/storage/notification-images/saved.png')
        ->assertSee('Delete');
});

it('sends and records a topic notification with an optional image', function () {
    Storage::fake('public');
    $admin = User::factory()->create();
    $sentMessage = null;
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->with(Mockery::on(function (CloudMessage $message) use (&$sentMessage): bool {
            $sentMessage = $message;

            return true;
        }))
        ->andReturn(['name' => 'projects/reelspay/messages/message-123']);
    $this->app->instance(Messaging::class, $messaging);

    $this->actingAs($admin)
        ->post(route('admin.notifications.store'), [
            'title' => 'New episode',
            'body' => 'A new episode is available.',
            'image' => UploadedFile::fake()->createWithContent(
                'banner.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/aV0AAAAASUVORK5CYII=')
            ),
        ])
        ->assertRedirect(route('admin.notifications.index'))
        ->assertSessionHas('status', 'Notification sent to the all topic.');

    $payload = $sentMessage->jsonSerialize();
    expect($sentMessage->target()->type())->toBe('topic')
        ->and($sentMessage->target()->value())->toBe('all')
        ->and($payload['notification']['title'])->toBe('New episode')
        ->and($payload['notification']['body'])->toBe('A new episode is available.')
        ->and(parse_url($payload['notification']['image'], PHP_URL_SCHEME))->toBe('http')
        ->and($payload['notification']['image'])->toContain('/storage/notification-images/');

    $notification = PushNotification::firstOrFail();
    expect($notification->status)->toBe('sent')
        ->and($notification->topic)->toBe('all')
        ->and($notification->firebase_message_id)->toBe('projects/reelspay/messages/message-123')
        ->and($notification->sent_at)->not->toBeNull();
    Storage::disk('public')->assertExists($notification->image_path);
});

it('sends a notification without an image', function () {
    $admin = User::factory()->create();
    $sentMessage = null;
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->with(Mockery::on(function (CloudMessage $message) use (&$sentMessage): bool {
            $sentMessage = $message;

            return true;
        }))
        ->andReturn(['name' => 'projects/reelspay/messages/message-456']);
    $this->app->instance(Messaging::class, $messaging);

    $this->actingAs($admin)
        ->post(route('admin.notifications.store'), [
            'title' => 'Text only',
            'body' => 'No image attached.',
        ])
        ->assertRedirect(route('admin.notifications.index'));

    expect($sentMessage->target()->type())->toBe('topic')
        ->and($sentMessage->target()->value())->toBe('all')
        ->and($sentMessage->jsonSerialize()['notification'])->toMatchArray([
            'title' => 'Text only',
            'body' => 'No image attached.',
        ])
        ->and(isset($sentMessage->jsonSerialize()['notification']['image']))->toBeFalse();

    $this->assertDatabaseHas('push_notifications', [
        'title' => 'Text only',
        'body' => 'No image attached.',
        'topic' => 'all',
        'image_path' => null,
        'status' => 'sent',
    ]);
});

it('records a failed notification when Firebase cannot send it', function () {
    $admin = User::factory()->create();
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException('Firebase is unavailable.'));
    $this->app->instance(Messaging::class, $messaging);

    $this->actingAs($admin)
        ->post(route('admin.notifications.store'), [
            'title' => 'Unavailable',
            'body' => 'This delivery should be recorded as failed.',
        ])
        ->assertRedirect(route('admin.notifications.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('push_notifications', [
        'title' => 'Unavailable',
        'status' => 'failed',
        'error_message' => 'Firebase could not deliver this notification. Check the application logs.',
    ]);
});

it('rejects a non-image attachment before sending', function () {
    $admin = User::factory()->create();
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('send');
    $this->app->instance(Messaging::class, $messaging);

    $this->actingAs($admin)
        ->post(route('admin.notifications.store'), [
            'title' => 'Invalid attachment',
            'body' => 'This should not be sent.',
            'image' => UploadedFile::fake()->create('payload.txt', 10, 'text/plain'),
        ])
        ->assertSessionHasErrors('image');

    $this->assertDatabaseCount('push_notifications', 0);
});

it('deletes notification history and its uploaded image', function () {
    Storage::fake('public');
    $admin = User::factory()->create();
    $notification = PushNotification::create([
        'title' => 'Old notice',
        'body' => 'This notice has expired.',
        'topic' => 'all',
        'image_path' => 'notification-images/old.jpg',
        'status' => 'sent',
    ]);
    Storage::disk('public')->put($notification->image_path, 'image');
    $this->app->instance(Messaging::class, Mockery::mock(Messaging::class));

    $this->actingAs($admin)
        ->delete(route('admin.notifications.destroy', $notification))
        ->assertRedirect(route('admin.notifications.index'))
        ->assertSessionHas('status', 'Notification and its image were deleted.');

    $this->assertDatabaseMissing('push_notifications', ['id' => $notification->id]);
    Storage::disk('public')->assertMissing('notification-images/old.jpg');
});
