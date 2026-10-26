<?php

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

it('requires authentication before importing videos', function () {
    $this->post(route('tiktok-library.import'), [
        'urls' => 'https://www.tiktok.com/@creator/video/123',
    ])
        ->assertRedirect(route('login'));
});

it('shows the full video library to a guest only when its secret token is valid', function () {
    $token = str_repeat('a', 64);
    config(['services.tiktok.upload_token' => $token]);
    Video::create([
        'source_url' => 'https://www.tiktok.com/@creator/video/guest-managed',
        'title' => 'Guest-managed video',
        'is_active' => true,
    ]);

    $this->get(route('tiktok-library.guest-upload', ['token' => $token]))
        ->assertOk()
        ->assertSee('Guest-managed video')
        ->assertSee('Import videos')
        ->assertSee('Edit')
        ->assertSee(route('tiktok-library.guest-edit', [
            'token' => $token,
            'video' => Video::firstOrFail(),
        ]))
        ->assertDontSee('Log out');

    $this->get(route('tiktok-library.index'))
        ->assertRedirect(route('login'));

    $this->get(route('tiktok-library.guest-upload', ['token' => str_repeat('b', 64)]))
        ->assertNotFound();
});

it('allows a token holder to update a video without logging in', function () {
    $token = str_repeat('a', 64);
    config(['services.tiktok.upload_token' => $token]);
    $video = Video::create([
        'source_url' => 'https://www.tiktok.com/@creator/video/guest-update',
        'title' => 'Original guest title',
        'is_active' => true,
    ]);

    $this->put(route('tiktok-library.guest-update', [
        'token' => $token,
        'video' => $video,
    ]), [
        'title' => 'Updated guest title',
        'author' => 'creator',
        'collection_title' => '',
        'is_active' => '1',
        'sort_order' => '0',
    ])
        ->assertRedirect(route('tiktok-library.guest-upload', ['token' => $token]))
        ->assertSessionHas('status', 'Video updated.');

    $this->assertDatabaseHas('videos', [
        'id' => $video->id,
        'title' => 'Updated guest title',
    ]);
});

it('rejects video management with an incorrect guest token', function () {
    config(['services.tiktok.upload_token' => str_repeat('a', 64)]);
    $video = Video::create([
        'source_url' => 'https://www.tiktok.com/@creator/video/guest-protected',
        'title' => 'Protected title',
        'is_active' => true,
    ]);

    $this->put(route('tiktok-library.guest-update', [
        'token' => str_repeat('b', 64),
        'video' => $video,
    ]), [
        'title' => 'Must not update',
        'is_active' => '1',
        'sort_order' => '0',
    ])->assertNotFound();

    $this->assertDatabaseHas('videos', [
        'id' => $video->id,
        'title' => 'Protected title',
    ]);
});

it('allows a token holder to delete a video without logging in', function () {
    $token = str_repeat('a', 64);
    config(['services.tiktok.upload_token' => $token]);
    Storage::fake('public');
    $video = Video::create([
        'source_url' => 'https://www.tiktok.com/@creator/video/guest-delete',
        'title' => 'Guest delete video',
        'video_path' => 'videos/guest-delete.mp4',
        'thumbnail_url' => 'thumbnails/guest-delete.jpg',
        'is_active' => true,
    ]);
    Storage::disk('public')->put($video->video_path, 'video');
    Storage::disk('public')->put($video->thumbnail_url, 'image');

    $this->delete(route('tiktok-library.guest-destroy', [
        'token' => $token,
        'video' => $video,
    ]))
        ->assertRedirect(route('tiktok-library.guest-upload', ['token' => $token]))
        ->assertSessionHas('status', 'Video deleted.');

    $this->assertDatabaseMissing('videos', ['id' => $video->id]);
    Storage::disk('public')->assertMissing('videos/guest-delete.mp4');
    Storage::disk('public')->assertMissing('thumbnails/guest-delete.jpg');
});

it('rejects guest imports that exceed the batch limit', function () {
    $token = str_repeat('a', 64);
    config(['services.tiktok.upload_token' => $token]);
    Http::preventStrayRequests();
    $urls = collect(range(1, 11))
        ->map(fn (int $number): string => "https://www.tiktok.com/@creator/video/{$number}")
        ->implode("\n");

    $this->post(route('tiktok-library.guest-import', ['token' => $token]), [
        'urls' => $urls,
    ])->assertSessionHasErrors('urls');

    $this->assertDatabaseCount('videos', 0);
});

it('allows a guest with the secret token to import TikTok videos', function () {
    $token = str_repeat('a', 64);
    config(['services.tiktok.upload_token' => $token]);
    Storage::fake('public');
    Http::preventStrayRequests();
    Http::fake([
        'www.tikwm.com/api/' => Http::response([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'title' => 'Guest imported video',
                'author' => ['unique_id' => 'creator'],
                'cover' => 'https://cdn.example.test/cover.jpg',
                'play' => 'https://cdn.example.test/video.mp4',
            ],
        ]),
        'cdn.example.test/cover.jpg' => Http::response('image', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
        'cdn.example.test/video.mp4' => Http::response(str_repeat('v', 1024), 200, [
            'Content-Type' => 'video/mp4',
        ]),
    ]);

    $this->post(route('tiktok-library.guest-import', ['token' => $token]), [
        'urls' => 'https://www.tiktok.com/@creator/video/123',
    ])
        ->assertRedirect(route('tiktok-library.guest-upload', ['token' => $token]))
        ->assertSessionHas('import_results.0.status', 'success');

    $this->assertDatabaseHas('videos', [
        'source_url' => 'https://www.tiktok.com/@creator/video/123',
        'title' => 'Guest imported video',
    ]);

    $video = Video::where('source_url', 'https://www.tiktok.com/@creator/video/123')
        ->firstOrFail();

    Storage::disk('public')->assertExists($video->video_path);
    expect(Storage::disk('public')->getVisibility($video->video_path))->toBe('public');
});

it('lists videos with their thumbnail and escapes their titles', function () {
    $admin = User::factory()->create();
    Video::create([
        'source_url' => 'https://www.tiktok.com/@creator/video/123',
        'title' => '<script>alert(1)</script>',
        'thumbnail_url' => 'thumbnails/cover.jpg',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('tiktok-library.index'))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('storage/thumbnails/cover.jpg')
        ->assertSee('Views')
        ->assertSee('Likes')
        ->assertSee('Comments')
        ->assertSee('Log out');
});

it('shows one row per collection with its episodes grouped for management', function () {
    $admin = User::factory()->create();
    Video::create([
        'source_url' => 'https://www.tiktok.com/@creator/video/season-1-episode-1',
        'title' => 'Episode One',
        'collection_id' => 'sample-season',
        'collection_title' => 'Sample Season',
        'episode_number' => 1,
        'is_active' => true,
    ]);
    Video::create([
        'source_url' => 'https://www.tiktok.com/@creator/video/season-1-episode-2',
        'title' => 'Episode Two',
        'collection_id' => 'sample-season',
        'collection_title' => 'Sample Season',
        'episode_number' => 2,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)
        ->get(route('tiktok-library.index'))
        ->assertOk()
        ->assertSee('View episodes')
        ->assertSee('Episode One')
        ->assertSee('Episode Two');

    expect(substr_count($response->getContent(), 'class="season-row"'))->toBe(1);
});

it('saves synthetic engagement metrics for every imported video', function () {
    Storage::fake('public');
    Http::preventStrayRequests();
    Http::fake([
        'www.tikwm.com/api/' => Http::response([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'title' => 'Imported video',
                'author' => ['unique_id' => 'creator'],
                'cover' => 'https://cdn.example.test/cover.jpg',
                'play' => 'https://cdn.example.test/video.mp4',
            ],
        ]),
        'cdn.example.test/cover.jpg' => Http::response('image', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
        'cdn.example.test/video.mp4' => Http::response(str_repeat('v', 1024), 200, [
            'Content-Type' => 'video/mp4',
        ]),
    ]);
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('tiktok-library.import'), [
            'urls' => 'https://www.tiktok.com/@creator/video/123',
        ])
        ->assertRedirect(route('tiktok-library.index'));

    $video = Video::firstOrFail();
    expect($video->views_count)->toBeGreaterThanOrEqual(100_000)
        ->and($video->views_count)->toBeLessThanOrEqual(500_000)
        ->and($video->likes_count)->toBeGreaterThanOrEqual(20_001)
        ->and($video->comments_count)->toBeGreaterThanOrEqual(20_000)
        ->and($video->views_count)->toBeGreaterThan($video->likes_count)
        ->and($video->likes_count)->toBeGreaterThan($video->comments_count);
});

it('updates editable video details', function () {
    $admin = User::factory()->create();
    $video = Video::create([
        'source_url' => 'https://www.tiktok.com/@creator/video/123',
        'title' => 'Old title',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->put(route('tiktok-library.update', $video), [
            'title' => 'New title',
            'author' => 'creator',
            'collection_title' => 'My series',
            'is_active' => '0',
            'sort_order' => '4',
        ])
        ->assertRedirect(route('tiktok-library.index'));

    $this->assertDatabaseHas('videos', [
        'id' => $video->id,
        'title' => 'New title',
        'author' => 'creator',
        'collection_id' => 'my-series',
        'collection_title' => 'My series',
        'is_active' => false,
        'sort_order' => 4,
    ]);
});

it('does not update a video with invalid display order', function () {
    $admin = User::factory()->create();
    $video = Video::create([
        'source_url' => 'https://www.tiktok.com/@creator/video/123',
        'title' => 'Original title',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->put(route('tiktok-library.update', $video), [
            'title' => 'Changed title',
            'is_active' => '1',
            'sort_order' => '-1',
        ])
        ->assertSessionHasErrors('sort_order');

    $this->assertDatabaseHas('videos', [
        'id' => $video->id,
        'title' => 'Original title',
    ]);
});

it('deletes a video and its locally stored media', function () {
    Storage::fake('public');
    $admin = User::factory()->create();
    $video = Video::create([
        'source_url' => 'https://www.tiktok.com/@creator/video/123',
        'title' => 'Video to delete',
        'video_path' => 'videos/video.mp4',
        'thumbnail_url' => 'thumbnails/cover.jpg',
        'is_active' => true,
    ]);
    Storage::disk('public')->put('videos/video.mp4', 'video');
    Storage::disk('public')->put('thumbnails/cover.jpg', 'image');

    $this->actingAs($admin)
        ->delete(route('tiktok-library.destroy', $video))
        ->assertRedirect(route('tiktok-library.index'));

    $this->assertDatabaseMissing('videos', ['id' => $video->id]);
    Storage::disk('public')->assertMissing('videos/video.mp4');
    Storage::disk('public')->assertMissing('thumbnails/cover.jpg');
});
