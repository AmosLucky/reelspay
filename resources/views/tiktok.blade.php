
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>TikTok Video Downloader</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 16px;
            font-family: Arial, sans-serif;
            background: #f4f4f5;
            color: #18181b;
        }

        .container {
            max-width: 560px;
            margin: 30px auto;
            padding: 28px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        h1 {
            text-align: center;
            margin-top: 0;
            font-size: 28px;
        }

        .description {
            text-align: center;
            color: #71717a;
            margin-bottom: 25px;
        }

        input[type="url"] {
            width: 100%;
            padding: 14px;
            border: 1px solid #d4d4d8;
            border-radius: 8px;
            font-size: 15px;
            margin-bottom: 12px;
        }

        button,
        .download {
            display: block;
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            background: #18181b;
            color: #fff;
            text-align: center;
            text-decoration: none;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover,
        .download:hover {
            background: #3f3f46;
        }

        .video-card {
            margin-top: 28px;
            padding-top: 22px;
            border-top: 1px solid #e4e4e7;
        }

        .thumbnail {
            display: block;
            width: 100%;
            max-height: 420px;
            object-fit: contain;
            border-radius: 10px;
            margin: 18px auto;
            background: #f4f4f5;
        }

        .video-title {
            font-size: 20px;
            overflow-wrap: anywhere;
        }

        .author {
            color: #71717a;
            margin-bottom: 20px;
        }

        .error {
            margin-top: 18px;
            padding: 12px;
            background: #fef2f2;
            color: #b91c1c;
            border-radius: 8px;
        }

        .help {
            margin-top: 20px;
            text-align: center;
            color: #71717a;
            font-size: 13px;
            line-height: 1.6;
        }

        @media (max-width: 480px) {
            .container {
                padding: 20px;
                margin: 10px auto;
            }

            h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>
    <main class="container">
        <h1>TikTok Downloader</h1>

        <p class="description">
            Paste a TikTok video link to get your video.
        </p>

        <form action="{{ url('/tiktok-page') }}" method="GET">
            <input
                type="url"
                name="url"
                placeholder="https://www.tiktok.com/@user/video/..."
                value="{{ is_string($url ?? null) ? $url : '' }}"
                required
            >

            <button type="submit">
                Get Video
            </button>
        </form>

        @if(!empty($error))
            <div class="error">
                {{ $error }}
            </div>
        @endif

        @if(!empty($video))
            <section class="video-card">
                <h2 class="video-title">
                    {{ $video['title'] ?? 'TikTok Video' }}
                </h2>

                @if(!empty($video['thumbnail']))
                    <img
                        class="thumbnail"
                        src="{{ $video['thumbnail'] }}"
                        alt="TikTok video thumbnail"
                        loading="lazy"
                    >
                @endif

                @if(!empty($video['author']))
                    <p class="author">
                        Creator: {{ '@' . $video['author'] }}
                    </p>
                @endif

                @if(!empty($video['source_url']))
                    <a
                        class="download"
                        href="{{ url('/tiktok-file') }}?url={{ urlencode($video['source_url']) }}"
                    >
                        Download MP4
                    </a>
                @else
                    <div class="error">
                        The original video URL is missing. Please try again.
                    </div>
                @endif
            </section>
        @endif

        <p class="help">
            Use this tool only for videos you have permission to download.
            Video availability depends on TikTok and the downloader service.
        </p>
    </main>
</body>
</html>
