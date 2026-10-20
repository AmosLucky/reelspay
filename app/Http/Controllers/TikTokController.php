<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TikTokController extends Controller
{
    private function validateTikTokUrl(string $url): void
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (
            !filter_var($url, FILTER_VALIDATE_URL) ||
            strtolower(parse_url($url, PHP_URL_SCHEME) ?? '') !== 'https' ||
            !(
                $host === 'tiktok.com' ||
                str_ends_with($host, '.tiktok.com')
            )
        ) {
            throw ValidationException::withMessages([
                'url' => 'Please enter a valid HTTPS TikTok URL.',
            ]);
        }
    }

    private function extractVideo(string $url): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0',
            ])
            ->timeout(30)
            ->post('https://www.tikwm.com/api/', [
                'url' => $url,
                'hd' => 1,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'TikWM returned HTTP ' . $response->status()
            );
        }

        $result = $response->json();

        if (
            !is_array($result) ||
            ($result['code'] ?? null) != 0 ||
            !is_array($result['data'] ?? null)
        ) {
            throw new RuntimeException(
                is_array($result)
                    ? ($result['msg'] ?? 'Video extraction failed.')
                    : 'Invalid API response.'
            );
        }

        return $result['data'];
    }

    private function getVideoUrl(array $data): string
    {
        $videoUrl = $data['play'] ?? null;

        if (
            !is_string($videoUrl) ||
            !filter_var($videoUrl, FILTER_VALIDATE_URL) ||
            strtolower(parse_url($videoUrl, PHP_URL_SCHEME) ?? '') !== 'https'
        ) {
            throw new RuntimeException('Invalid video URL returned by API.');
        }

        $host = strtolower(parse_url($videoUrl, PHP_URL_HOST) ?? '');

        $allowedHosts = [
            'tiktokcdn.com',
            'tiktokcdn-us.com',
            'tiktokcdn-eu.com',
            'tiktokcdn-in.com',
        ];

        $allowed = false;

        foreach ($allowedHosts as $allowedHost) {
            if (
                $host === $allowedHost ||
                str_ends_with($host, '.' . $allowedHost)
            ) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            throw new RuntimeException(
                'The video URL uses an unrecognized CDN host: ' . $host
            );
        }

        return $videoUrl;
    }

    /**
     * Show the downloader page and extract video information.
     */
    public function page(Request $request)
    {
        $video = null;
        $error = null;
        $url = $request->query('url', '');

        if ($url !== '') {
            try {
                $this->validateTikTokUrl($url);

                $data = $this->extractVideo($url);

                $video = [
                    'title' => $data['title'] ?? 'TikTok video',
                    'thumbnail' => $data['cover'] ?? null,
                    'author' => $data['author']['unique_id'] ?? '',
                    'source_url' => $url,
                ];
            } catch (Throwable $e) {
                Log::warning('TikTok extraction failed', [
                    'message' => $e->getMessage(),
                ]);

                $error = $e instanceof ValidationException
                    ? 'Please enter a valid HTTPS TikTok link.'
                    : 'Could not retrieve this video. Check the URL and try again.';
            }
        }

        return view('tiktok', compact('video', 'error', 'url'));
    }

    /**
     * Return video details as JSON.
     */
    public function download(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        try {
            $this->validateTikTokUrl($validated['url']);

            $data = $this->extractVideo($validated['url']);
            $videoUrl = $this->getVideoUrl($data);

            return response()->json([
                'success' => true,
                'title' => $data['title'] ?? null,
                'thumbnail' => $data['cover'] ?? null,
                'author' => $data['author']['unique_id'] ?? null,
                'download_url' => $videoUrl,
            ]);
        } catch (Throwable $e) {
            Log::error('TikTok JSON extraction failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Could not extract this video.',
            ], 502);
        }
    }

    /**
     * Stream the video to the browser as an MP4 download.
     */
    
public function file(Request $request)
{
    $validated = $request->validate([
        'url' => ['required', 'url', 'max:2048'],
    ]);

    try {
        $this->validateTikTokUrl($validated['url']);

        $data = $this->extractVideo($validated['url']);
        $videoUrl = $data['play'] ?? null;

        if (
            !is_string($videoUrl) ||
            !filter_var($videoUrl, FILTER_VALIDATE_URL) ||
            strtolower(parse_url($videoUrl, PHP_URL_SCHEME) ?? '') !== 'https'
        ) {
            throw new RuntimeException('Invalid video URL.');
        }

        // Follow redirects because TikTok CDN URLs may redirect.
        $upstream = Http::withOptions([
            'stream' => true,
            'allow_redirects' => [
                'max' => 5,
                'protocols' => ['https'],
            ],
        ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0',
                'Accept' => 'video/mp4,video/*,*/*',
            ])
            ->timeout(120)
            ->get($videoUrl);

        if (!$upstream->successful()) {
            throw new RuntimeException(
                'Video server returned HTTP ' . $upstream->status()
            );
        }

        $contentType = $upstream->header('Content-Type', '');

        if (
            stripos($contentType, 'video/') === false &&
            stripos($contentType, 'octet-stream') === false
        ) {
            throw new RuntimeException(
                'The video server returned an unexpected content type: '
                . $contentType
            );
        }

        $body = $upstream->toPsrResponse()->getBody();

        return response()->streamDownload(
            function () use ($body) {
                while (!$body->eof()) {
                    echo $body->read(16384);

                    if (connection_aborted()) {
                        break;
                    }

                    flush();
                }
            },
            'tiktok-video.mp4',
            [
                'Content-Type' => 'video/mp4',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    } catch (Throwable $e) {
        Log::error('TikTok MP4 download failed', [
            'message' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'error' => 'The video could not be downloaded.',
        ], 502);
    }
}

    public function filej(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        try {
            $this->validateTikTokUrl($validated['url']);

            // Resolve a fresh CDN URL rather than trusting a URL from the browser.
            $data = $this->extractVideo($validated['url']);
            $videoUrl = $this->getVideoUrl($data);

            $upstream = Http::withOptions([
                'stream' => true,
                'allow_redirects' => false,
            ])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                ])
                ->timeout(60)
                ->get($videoUrl);

            if (!$upstream->successful()) {
                throw new RuntimeException(
                    'Video server returned HTTP ' . $upstream->status()
                );
            }

            $body = $upstream->toPsrResponse()->getBody();

            return response()->streamDownload(
                function () use ($body) {
                    while (!$body->eof()) {
                        echo $body->read(8192);

                        if (connection_aborted()) {
                            break;
                        }

                        flush();
                    }
                },
                'tiktok-video.mp4',
                [
                    'Content-Type' => 'video/mp4',
                    'X-Content-Type-Options' => 'nosniff',
                ]
            );
        } catch (Throwable $e) {
            Log::error('TikTok MP4 download failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'The video could not be downloaded. Please try again.',
            ], 502);
        }
    }
}
