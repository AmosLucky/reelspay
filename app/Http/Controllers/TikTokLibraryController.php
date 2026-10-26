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
    public function index(Request $request): View
    {
        $guestUploadToken = $this->guestUploadTokenForRequest($request);

        $collectionGroups = Video::query()

            ->whereNotNull('collection_id')

            ->selectRaw('collection_id, MIN(collection_title) as collection_title, MIN(id) as representative_id, COUNT(*) as episode_count, MIN(sort_order) as sort_order, 0 as is_standalone')

            ->groupBy('collection_id');

        $standaloneVideos = Video::query()

            ->whereNull('collection_id')

            ->selectRaw('NULL as collection_id, NULL as collection_title, id as representative_id, 1 as episode_count, sort_order, 1 as is_standalone');

        // $videoGroups = DB::query()

        //     ->fromSub($collectionGroups->unionAll($standaloneVideos), 'video_groups')

        //     ->orderByDesc('id')

        //     ->orderByDesc('representative_id')

        //     ->paginate(20);

        $videoGroups = DB::query()
            ->fromSub($collectionGroups->unionAll($standaloneVideos), 'video_groups')
            ->orderByDesc('representative_id')
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

            ->orderByDesc('id')

            ->orderByDesc('id')

            ->orderByDesc('id')

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

                    'title' => $this->resolveCollectionTitle(
                        $group->collection_title,
                        $groupEpisodes->first()?->title
                    ),

                    'is_standalone' => (bool) $group->is_standalone,

                    'episode_count' => (int) $group->episode_count,

                    'episodes' => $groupEpisodes,

                    'representative' => $groupEpisodes->first(),

                ];

            })

        );

        return view('tiktok-library', compact('videoGroups', 'guestUploadToken'));

    }

    public function edit(Request $request, Video $video): View
    {
        $guestUploadToken = $this->guestUploadTokenForRequest($request);

        return view('tiktok-library-edit', compact('video', 'guestUploadToken'));

    }

    public function guestEdit(Request $request, string $token, Video $video): View
    {
        $this->authorizeGuestUploadToken($token);

        return $this->edit($request, $video);

    }

    public function update(Request $request, Video $video): RedirectResponse
    {
        $guestUploadToken = $this->guestUploadTokenForRequest($request);

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

        $redirect = $guestUploadToken !== null
            ? redirect()->route('tiktok-library.guest-upload', ['token' => $guestUploadToken])
            : redirect()->route('tiktok-library.index');

        return $redirect->with('status', 'Video updated.');

    }

    public function guestUpdate(Request $request, string $token, Video $video): RedirectResponse
    {
        $this->authorizeGuestUploadToken($token);

        return $this->update($request, $video);

    }

    public function destroy(Request $request, Video $video): RedirectResponse
    {
        $guestUploadToken = $this->guestUploadTokenForRequest($request);

        $videoPath = $video->video_path;

        $thumbnailPath = $video->thumbnail_url;

        $this->deleteLocalFile($videoPath, 'videos/');

        $this->deleteLocalFile($thumbnailPath, 'thumbnails/');

        $video->delete();

        $redirect = $guestUploadToken !== null
            ? redirect()->route('tiktok-library.guest-upload', ['token' => $guestUploadToken])
            : redirect()->route('tiktok-library.index');

        return $redirect->with('status', 'Video deleted.');

    }

    public function guestDestroy(Request $request, string $token, Video $video): RedirectResponse
    {
        $this->authorizeGuestUploadToken($token);

        return $this->destroy($request, $video);

    }

    public function import(Request $request): RedirectResponse
    {

        $guestUploadToken = $this->guestUploadTokenForRequest($request);
        $isGuestUpload = $guestUploadToken !== null;

        $maximumUrls = $isGuestUpload ? 10 : 100;

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

        if (count($urls) > $maximumUrls) {

            throw ValidationException::withMessages([
                'urls' => 'Please import no more than '.$maximumUrls.' links at once.',

            ]);

        }

        $collectionTitle = trim(

            $validated['collection_title'] ?? ''

        );

        // One import request represents one collection. Generate the ID once
        // so every successfully imported URL in this batch shares it.
        $collectionId = (string) Str::uuid();

        $sortOrder = (int) Video::max('sort_order');

        // This is a fresh unique collection, so episode numbering starts at 1.
        $episodeOrder = 0;

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

                // If no title was supplied, use the first imported video's title as
                // the collection label. The UUID remains the grouping key.
                if ($collectionTitle === '') {
                    $collectionTitle = trim((string) ($video['title'] ?? ''));
                    $collectionTitle = $this->inferCollectionTitle($collectionTitle)
                        ?: ($collectionTitle !== ''
                            ? Str::limit($collectionTitle, 200, '')
                            : 'Collection '.now()->format('Y-m-d H:i:s'));
                }

                $fields['collection_id'] = $collectionId;
                $fields['collection_title'] = $collectionTitle;
                $fields['episode_number'] = $nextEpisodeOrder;

                Video::create($fields);

                $sortOrder = $nextSortOrder;
                $episodeOrder = $nextEpisodeOrder;

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

        $redirect = $isGuestUpload
            ? redirect()->route('tiktok-library.guest-upload', [
                'token' => $guestUploadToken,
            ])
            : redirect()->route('tiktok-library.index');

        return $redirect->with('import_results', $results);

    }

    private function guestUploadTokenForRequest(Request $request): ?string
    {
        $routeName = $request->route()?->getName();

        if (! is_string($routeName) || ! str_starts_with($routeName, 'tiktok-library.guest-')) {
            return null;
        }

        $token = $request->route('token');

        abort_unless(is_string($token), 404);

        $this->authorizeGuestUploadToken($token);

        return $token;

    }

    private function authorizeGuestUploadToken(string $token): void
    {
        $expectedToken = config('services.tiktok.upload_token');

        abort_unless(
            is_string($expectedToken)
                && $expectedToken !== ''
                && hash_equals($expectedToken, $token),
            404
        );

    }

    /**
     * Recover a readable collection title when older records have a collection_id
     * but no collection_title saved. Supports titles such as
     * "Book of Daniel — Part 1: The Day Jerusalem Fell ...".
     */
    /**
     * Prefer a real collection name over a full episode caption. This also
     * repairs the display of older rows whose collection_title contains the
     * entire TikTok caption rather than just the series name.
     */
    private function resolveCollectionTitle(?string $savedCollectionTitle, ?string $videoTitle): string
    {
        $savedCollectionTitle = trim((string) $savedCollectionTitle);

        // If the saved value itself looks like an episode title, extract the
        // series name from it (e.g. 'Book of Daniel — Part 1: ...').
        $inferredSavedTitle = $this->inferCollectionTitle($savedCollectionTitle);
        if ($inferredSavedTitle !== null && preg_match('/\bpart\s*\d+\b/i', $savedCollectionTitle) === 1) {
            return $inferredSavedTitle;
        }

        if ($savedCollectionTitle !== '') {
            return Str::limit($savedCollectionTitle, 200, '');
        }

        return $this->inferCollectionTitle($videoTitle)
            ?: 'Untitled collection';
    }

    private function inferCollectionTitle(?string $videoTitle): ?string
    {
        $title = trim((string) $videoTitle);

        if ($title === '') {
            return null;
        }

        if (preg_match('/^(.+?)\s*(?:[-–—:|]\s*)?part\s*\d+\b/i', $title, $matches) === 1) {
            $collectionTitle = trim($matches[1], " \t\n\r\0\x0B-–—:|.");

            if ($collectionTitle !== '') {
                return Str::limit($collectionTitle, 200, '');
            }
        }

        // Last-resort fallback for legacy records without a Part N pattern.
        return Str::limit($title, 200, '');
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

            $videoStream = fopen($tempPath, 'rb');

            if ($videoStream === false) {

                throw new RuntimeException(
                    'Could not open the downloaded video for storage.'

                );

            }

            try {

                $stored = $disk->put(
                    $relativePath,
                    $videoStream,
                    ['visibility' => 'public']
                );

            } finally {

                fclose($videoStream);

            }

            if (! $stored) {

                throw new RuntimeException(
                    'Could not store the video with public visibility.'

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

        return $this->groupedApiVideos($request, true);

    }

    public function apiVideos2(Request $request)
    {

        return $this->groupedApiVideos($request, false);

    }

    private function groupedApiVideos(Request $request, bool $includeMetrics)
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

            ->orderByDesc('id')

            ->orderByDesc('id')

            ->orderByDesc('id')

            ->get();

        // Group episodes by collection.

        // Videos without a collection remain standalone items.

        $groups = $videos->groupBy(

            fn (Video $video) => $video->collection_id !== null

                ? 'collection:'.$video->collection_id

                : 'video:'.$video->id

        );

        $items = $groups->map(function ($episodes) use ($includeMetrics) {

            /** @var Video $first */
            $first = $episodes->first();

            // Convert a saved thumbnail path into a public URL.

            $thumbnailUrl = function (?string $path): ?string {

                if (! $path) {

                    return null;

                }

                // Keep external image URLs unchanged.

                if (

                    str_starts_with($path, 'http://') ||

                    str_starts_with($path, 'https://')

                ) {

                    return $path;

                }

                return url(Storage::disk('public')->url($path));

            };

            // Each episode gets its own thumbnail from its own Video record.

            $episodeData = $episodes->map(

                function (Video $video) use ($thumbnailUrl, $includeMetrics) {

                    $episodeThumbnail = $thumbnailUrl(

                        $video->thumbnail_url

                    );

                    $episode = [

                        'id' => $video->id,

                        'title' => $video->title ?: 'Untitled episode',

                        'author' => $video->author,

                        // This episode's own thumbnail.

                        'thumbnail_url' => $episodeThumbnail,

                        'thumbnail' => $episodeThumbnail,

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

                    ];

                    if ($includeMetrics) {

                        $episode['views_count'] = $video->views_count;

                        $episode['likes_count'] = $video->likes_count;

                        $episode['comments_count'] = $video->comments_count;

                    }

                    return $episode;

                }

            )->values();

            $isCollection = $first->collection_id !== null;

            $coverThumbnail = $thumbnailUrl($first->thumbnail_url);

            $item = [

                // A collection uses its collection ID.

                // A standalone video uses its video ID.

                'id' => $isCollection

                    ? $first->collection_id

                    : $first->id,

                'type' => $isCollection ? 'collection' : 'video',

                'title' => $isCollection

                    ? $this->resolveCollectionTitle($first->collection_title, $first->title)

                    : ($first->title ?: 'Untitled video'),

                'collection_id' => $first->collection_id,

                'collection_title' => $isCollection
                    ? $this->resolveCollectionTitle($first->collection_title, $first->title)
                    : null,

                // Collection card cover, separate from episode thumbnails.

                'cover_thumbnail_url' => $coverThumbnail,

                // Keep this field for compatibility with existing clients.

                'thumbnail_url' => $coverThumbnail,

                'episode_count' => $episodes->count(),

                'episodes' => $episodeData,

            ];

            // Preserve useful top-level fields for standalone videos.

            if (! $isCollection) {

                $singleEpisode = $episodeData->first();

                $item['author'] = $first->author;

                $item['video_url'] = $singleEpisode['video_url'] ?? null;

                $item['thumbnail'] = $singleEpisode['thumbnail_url'] ?? null;

                if ($includeMetrics) {

                    $item['views_count'] = $first->views_count;

                    $item['likes_count'] = $first->likes_count;

                    $item['comments_count'] = $first->comments_count;

                }

            }

            return $item;

        })->values();

        // Paginate collection cards, not individual episodes.

        $perPage = max(

            1,

            min((int) $request->query('per_page', 20), 100)

        );

        $page = max(1, (int) $request->query('page', 1));

        $pagedItems = $items

            ->forPage($page, $perPage)

            ->values();

        return response()->json([

            'data' => $pagedItems,

            'current_page' => $page,

            'per_page' => $perPage,

            'total' => $items->count(),

            'last_page' => max(

                1,

                (int) ceil($items->count() / $perPage)

            ),

        ]);

    }

    public function apiVideos3(Request $request)
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

            ->orderByDesc('id')

            ->orderByDesc('id')

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

    public function apiVideos2e(Request $request)
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

            ->orderByDesc('id')

            ->orderByDesc('id')

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
