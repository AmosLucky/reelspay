<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class TikTokLibraryController extends Controller
{
    public function index(): View
    {
        $collectionGroups = Video::query()
            ->whereNotNull('collection_id')
            ->selectRaw('collection_id, MIN(collection_title) as collection_title, MIN(id) as representative_id, COUNT(*) as episode_count, MIN(sort_order) as sort_order, 0 as is_standalone')
            ->groupBy('collection_id');

        $standaloneVideos = Video::query()
            ->whereNull('collection_id')
            ->selectRaw('NULL as collection_id, NULL as collection_title, id as representative_id, 1 as episode_count, sort_order, 1 as is_standalone');

        $videoGroups = DB::query()
            ->fromSub($collectionGroups->unionAll($standaloneVideos), 'video_groups')
            ->orderBy('sort_order')
            ->orderBy('representative_id')
            ->paginate(20);

        $collectionIds = $videoGroups->getCollection()
            ->where('is_standalone', 0)
            ->pluck('collection_id')
            ->all();
        $standaloneIds = $videoGroups->getCollection()
            ->where('is_standalone', 1)
            ->pluck('representative_id')
            ->all();

        $episodes = Video::query()
            ->where(function ($query) use ($collectionIds, $standaloneIds): void {
                $query->whereIn('collection_id', $collectionIds)
                    ->orWhereIn('id', $standaloneIds);
            })
            ->orderBy('episode_number')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Video $video): string => $video->collection_id !== null
                ? 'collection:'.$video->collection_id
                : 'video:'.$video->id);

        $videoGroups->setCollection(
            $videoGroups->getCollection()->map(function (object $group) use ($episodes): array {
                $key = $group->is_standalone
                    ? 'video:'.$group->representative_id
                    : 'collection:'.$group->collection_id;
                $groupEpisodes = $episodes->get($key, collect());

                return [
                    'title' => $group->collection_title ?: 'Untitled collection',
                    'is_standalone' => (bool) $group->is_standalone,
                    'episode_count' => (int) $group->episode_count,
                    'episodes' => $groupEpisodes,
                    'representative' => $groupEpisodes->first(),
                ];
            })
        );

        return view('tiktok-library', compact('videoGroups'));
    }

    public function edit(Video $video): View
    {
        return view('tiktok-library-edit', compact('video'));
    }

    public function update(Request $request, Video $video): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:500'],
            'author' => ['nullable', 'string', 'max:255'],
            'collection_title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $collectionTitle = trim($validated['collection_title'] ?? '');

        $video->update([
            'title' => $validated['title'] ?? null,
            'author' => $validated['author'] ?? null,
            'collection_title' => $collectionTitle !== '' ? $collectionTitle : null,
            'collection_id' => $collectionTitle !== '' ? Str::slug($collectionTitle) : null,
            'is_active' => $validated['is_active'],
            'sort_order' => $validated['sort_order'],
        ]);

        return redirect()
            ->route('tiktok-library.index')
            ->with('status', 'Video updated.');
    }

    public function destroy(Video $video): RedirectResponse
    {
        $videoPath = $video->video_path;
        $thumbnailPath = $video->thumbnail_url;

        $this->deleteLocalFile($videoPath, 'videos/');
        $this->deleteLocalFile($thumbnailPath, 'thumbnails/');
        $video->delete();

        return redirect()
            ->route('tiktok-library.index')
            ->with('status', 'Video deleted.');
    }

    public function import(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'urls' => ['required', 'string', 'max:50000'],
            'collection_title' => ['nullable', 'string', 'max:200'],
        ]);

        // Accept URLs on separate lines or separated by commas.
        $urls = preg_split('/[\r\n,]+/', $validated['urls']);

        $urls = array_values(array_unique(array_filter(
            array_map('trim', $urls),
            fn ($url) => $url !== ''
        )));

        if (count($urls) === 0) {
            throw ValidationException::withMessages([
                'urls' => 'Please enter at least one TikTok URL.',
            ]);
        }

        if (count($urls) > 100) {
            throw ValidationException::withMessages([
                'urls' => 'Please import no more than 100 links at once.',
            ]);
        }

        $collectionTitle = trim(
            $validated['collection_title'] ?? ''
        );

        $collectionId = $collectionTitle !== ''
            ? Str::slug($collectionTitle)
            : null;

        $sortOrder = (int) Video::max('sort_order');

        $episodeOrder = $collectionId
            ? (int) Video::where(
                'collection_id',
                $collectionId
            )->max('episode_number')
            : 0;

        $results = [];

        foreach ($urls as $url) {
            if (! $this->isTikTokUrl($url)) {
                $results[] = [
                    'url' => $url,
                    'status' => 'failed',
                    'message' => 'This is not a valid TikTok URL.',
                ];

                continue;
            }

            // Avoid downloading a URL that is already in the library.
            if (Video::where('source_url', $url)->exists()) {
                $results[] = [
                    'url' => $url,
                    'status' => 'skipped',
                    'message' => 'This URL is already in your library.',
                ];

                continue;
            }

            $savedPath = null;
            $thumbnailPath = null;

            try {
                $video = $this->extractVideo($url);
                // Download the thumbnail to local public storage.
                $thumbnailPath = $this->downloadThumbnail($video['thumbnail']);

                // Generate simulated engagement metrics.
                $metrics = $this->generateEngagementMetrics();

                // Each downloaded file gets its own unique filename.
                $filename = 'video_'.Str::uuid().'.mp4';

                $download = $this->downloadVideo(
                    $video['download_url'],
                    $filename
                );

                $savedPath = $download['path'];

                $nextSortOrder = $sortOrder + 1;
                $nextEpisodeOrder = $episodeOrder + 1;

                $fields = [
                    'source_url' => $url,
                    'title' => Str::limit($video['title'], 500, ''),
                    'author' => $video['author'],
                    'thumbnail_url' => $thumbnailPath,
                    'views_count' => $metrics['views'],
                    'likes_count' => $metrics['likes'],
                    'comments_count' => $metrics['comments'],

                    // Don't rely on TikWM's temporary URL.
                    'video_url' => null,

                    // This relative path points to the local MP4 file.
                    'video_path' => $download['path'],
                    'video_size_bytes' => $download['size'],

                    'provider' => 'tikwm',
                    'sort_order' => $nextSortOrder,
                    'is_active' => true,
                ];

                if ($collectionId !== null) {
                    $fields['collection_id'] = $collectionId;
                    $fields['collection_title'] = $collectionTitle;
                    $fields['episode_number'] = $nextEpisodeOrder;
                }

                Video::create($fields);

                $sortOrder = $nextSortOrder;

                if ($collectionId !== null) {
                    $episodeOrder = $nextEpisodeOrder;
                }

                $results[] = [
                    'url' => $url,
                    'status' => 'success',
                    'title' => $video['title'] ?: 'Untitled video',
                    'message' => 'Video downloaded and saved locally.',
                ];
            } catch (\Throwable $e) {
                if ($savedPath !== null) {
                    Storage::disk('public')->delete($savedPath);
                }

                if ($thumbnailPath !== null) {
                    Storage::disk('public')->delete($thumbnailPath);
                }

                report($e);

                $results[] = [
                    'url' => $url,
                    'status' => 'failed',
                    'message' => 'Could not download or save this video. Check storage/logs/laravel.log.',
                ];
            }
        }

        return redirect()
            ->route('tiktok-library.index')
            ->with('import_results', $results);
    }

    private function deleteLocalFile(?string $path, string $directory): void
    {
        if (
            $path === null
            || ! str_starts_with($path, $directory)
            || in_array('..', explode('/', $path), true)
        ) {
            return;
        }

        $disk = Storage::disk('public');

        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new RuntimeException('Could not delete stored file: '.$path);
        }
    }

    private function isTikTokUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = strtolower(
            (string) parse_url($url, PHP_URL_SCHEME)
        );

        return is_string($host)
            && preg_match('/(^|\.)tiktok\.com$/i', $host) === 1
            && in_array($scheme, ['http', 'https'], true);
    }

    private function extractVideo(string $url): array
    {
        $response = Http::asForm()
            ->timeout(30)
            ->post('https://www.tikwm.com/api/', [
                'url' => $url,
                'hd' => 1,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'TikWM returned HTTP '.$response->status()
            );
        }

        $json = $response->json();

        // TikWM uses code = 0 and msg = success.
        if (
            ! is_array($json) ||
            (int) ($json['code'] ?? -1) !== 0 ||
            strtolower((string) ($json['msg'] ?? '')) !== 'success'
        ) {
            throw new RuntimeException(
                'TikWM extraction failed: '.
                (string) ($json['msg'] ?? 'Unknown API error')
            );
        }

        $data = $json['data'] ?? [];

        if (! is_array($data)) {
            throw new RuntimeException(
                'TikWM returned invalid video information.'
            );
        }

        // Prefer the HD link, then fall back to other available links.
        $downloadUrl = $data['play']
        ?? $data['hdplay']
        ?? $data['download_url']
        ?? null;

        if (! is_string($downloadUrl) || $downloadUrl === '') {
            throw new RuntimeException(
                'TikWM did not provide a video download URL.'
            );
        }

        $author = $data['author'] ?? '';

        if (is_array($author)) {
            $author = $author['unique_id']
                ?? $author['nickname']
                ?? '';
        }

        if (! is_string($author)) {
            $author = '';
        }

        $thumbnail = $data['cover']
            ?? $data['origin_cover']
            ?? '';

        return [
            'title' => is_string($data['title'] ?? null)
                ? $data['title']
                : '',
            'author' => $author,
            'thumbnail' => is_string($thumbnail)
                ? $thumbnail
                : '',
            'download_url' => $downloadUrl,
        ];
    }

    private function extractVideo2(string $url): array
    {
        $response = Http::asForm()
            ->timeout(30)
            ->post('https://www.tikwm.com/api/', [
                'url' => $url,
                'hd' => 1,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'TikWM returned HTTP '.$response->status()
            );
        }

        $json = $response->json();

        if (! is_array($json) || ! ($json['success'] ?? false)) {
            throw new RuntimeException(
                'TikWM could not extract this URL.'
            );
        }

        $data = $json['data'] ?? [];

        if (! is_array($data)) {
            throw new RuntimeException(
                'TikWM returned invalid video information.'
            );
        }

        $downloadUrl = $data['download_url']
            ?? $data['play']
            ?? null;

        if (! is_string($downloadUrl) || $downloadUrl === '') {
            throw new RuntimeException(
                'TikWM did not provide a download URL.'
            );
        }

        $author = $data['author'] ?? '';

        if (is_array($author)) {
            $author = $author['unique_id'] ?? '';
        }

        if (! is_string($author)) {
            $author = '';
        }

        $thumbnail = $data['cover']
            ?? $data['origin_cover']
            ?? $data['thumbnail']
            ?? '';

        return [
            'title' => is_string($data['title'] ?? null)
                ? $data['title']
                : '',
            'author' => $author,
            'thumbnail' => is_string($thumbnail)
                ? $thumbnail
                : '',
            'download_url' => $downloadUrl,
        ];
    }

    private function downloadVideo(
        string $downloadUrl,
        string $filename
    ): array {
        // Only allow HTTPS download links.
        $parts = parse_url($downloadUrl);

        if (
            ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
        ) {
            throw new RuntimeException(
                'The returned download URL is invalid.'
            );
        }

        // Download to a temporary file first.
        $tempPath = tempnam(storage_path('app'), 'tiktok_');

        if ($tempPath === false) {
            throw new RuntimeException(
                'Could not create a temporary download file.'
            );
        }

        try {
            // Stream to disk instead of loading the entire video into RAM.
            $response = Http::withOptions([
                'sink' => $tempPath,
            ])
                ->timeout(120)
                ->get($downloadUrl);

            if (! $response->successful()) {
                throw new RuntimeException(
                    'Video download returned HTTP '
                    .$response->status()
                );
            }

            $contentType = strtolower(
                (string) $response->header('Content-Type')
            );

            if (
                str_contains($contentType, 'text/html')
                || str_contains($contentType, 'application/json')
            ) {
                throw new RuntimeException(
                    'The download URL returned a webpage, not a video.'
                );
            }

            $size = filesize($tempPath);

            if ($size === false || $size < 1024) {
                throw new RuntimeException(
                    'The downloaded file is empty or too small.'
                );
            }

            // Keep individual downloads within a reasonable limit.
            $maxBytes = 300 * 1024 * 1024;

            if ($size > $maxBytes) {
                throw new RuntimeException(
                    'The video exceeds the 300 MB download limit.'
                );
            }

            $relativePath = 'videos/'.$filename;

            $disk = Storage::disk('public');
            $disk->makeDirectory('videos');

            $destination = $disk->path($relativePath);

            if (! rename($tempPath, $destination)) {
                throw new RuntimeException(
                    'Could not move the video into local storage.'
                );
            }

            return [
                'path' => $relativePath,
                'size' => $size,
            ];
        } finally {
            // Remove temporary files left behind after errors.
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Download and store a thumbnail locally.
     * Returns a relative path on the public storage disk.
     */
    private function downloadThumbnail(string $thumbnailUrl): ?string
    {
        if ($thumbnailUrl === '') {
            return null;
        }

        $parts = parse_url($thumbnailUrl);

        if (
            ! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
        ) {
            return null;
        }

        try {
            $response = Http::timeout(20)->get($thumbnailUrl);

            if (! $response->successful()) {
                return null;
            }

            $contentType = strtolower(
                (string) $response->header('Content-Type')
            );

            $extensions = [
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];

            $mimeType = trim(explode(';', $contentType)[0]);

            if (! isset($extensions[$mimeType])) {
                return null;
            }

            $contents = $response->body();

            // Avoid unexpectedly large thumbnail downloads.
            if (
                $contents === ''
                || strlen($contents) > 10 * 1024 * 1024
            ) {
                return null;
            }

            $path = 'thumbnails/'.Str::uuid()
                .'.'.$extensions[$mimeType];

            Storage::disk('public')->put($path, $contents);

            return $path;
        } catch (\Throwable $e) {
            report($e);

            // Keep importing the video if the thumbnail download fails.
            return null;
        }
    }

    /**
     * Generate simulated metrics with consistent relationships:
     * views > likes > comments.
     */
    private function generateEngagementMetrics(): array
    {
        $views = random_int(100_000, 500_000);

        $maxLikes = (int) floor($views * 0.30);

        $likes = random_int(
            20_001,
            $maxLikes
        );

        $comments = random_int(
            20_000,
            $likes - 1
        );

        return [
            'views' => $views,
            'likes' => $likes,
            'comments' => $comments,
        ];
    }

    public function apiVideos(Request $request)
    {
        $videos = Video::query()
            ->where('is_active', true)
            ->when(
                $request->filled('collection_id'),
                fn ($query) => $query->where(
                    'collection_id',
                    $request->query('collection_id')
                )
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20);

        return $videos->through(function (Video $video) {
            return [
                'id' => $video->id,
                'title' => $video->title,
                'author' => $video->author,

                // Return the full URL for locally stored thumbnails,
                // while preserving existing external thumbnail URLs.
                'thumbnail_url' => $video->thumbnail_url
                    ? (
                        str_starts_with($video->thumbnail_url, 'http://')
                        || str_starts_with($video->thumbnail_url, 'https://')
                            ? $video->thumbnail_url
                            : url(
                                Storage::disk('public')->url(
                                    $video->thumbnail_url
                                )
                            )
                    )
                    : null,

                'video_url' => $video->video_path
                    ? url(
                        Storage::disk('public')->url(
                            $video->video_path
                        )
                    )
                    : null,

                'collection_id' => $video->collection_id,
                'collection_title' => $video->collection_title,
                'episode_number' => $video->episode_number,

                // Generated engagement metrics.
                'views_count' => $video->views_count,
                'likes_count' => $video->likes_count,
                'comments_count' => $video->comments_count,
            ];
        });
    }

    public function apiVideos2(Request $request)
    {
        $videos = Video::query()
            ->where('is_active', true)
            ->when(
                $request->filled('collection_id'),
                fn ($query) => $query->where(
                    'collection_id',
                    $request->query('collection_id')
                )
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20);

        return $videos->through(function (Video $video) {
            return [
                'id' => $video->id,
                'title' => $video->title,
                'author' => $video->author,
                'thumbnail_url' => $video->thumbnail_url,
                'video_url' => $video->video_path
                    ? url(Storage::disk('public')->url($video->video_path))
                    : null,
                'collection_id' => $video->collection_id,
                'collection_title' => $video->collection_title,
                'episode_number' => $video->episode_number,
            ];
        });
    }
}
