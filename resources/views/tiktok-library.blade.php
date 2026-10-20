
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TikTok Library Admin</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 0 18px;
            background: #f5f6fa;
            color: #222;
        }
        .panel {
            background: white;
            padding: 24px;
            border-radius: 14px;
            box-shadow: 0 3px 15px #00000010;
        }
        textarea, input {
            box-sizing: border-box;
            width: 100%;
            padding: 12px;
            margin: 8px 0 18px;
            border: 1px solid #ccc;
            border-radius: 8px;
        }
        textarea { min-height: 220px; resize: vertical; }
        button {
            background: #111;
            color: white;
            border: 0;
            padding: 13px 20px;
            border-radius: 8px;
            cursor: pointer;
        }
        .success { color: #087443; }
        .failed { color: #b42318; }
        .error { color: #b42318; margin-bottom: 14px; }
    </style>
</head>
<body>
    <div class="panel">
        <h1>TikTok Library</h1>
        <p>Paste TikTok links, one per line or separated by commas.</p>

        @if ($errors->any())
            <div class="error">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('tiktok-library.import') }}">
            @csrf

            <label for="urls">TikTok URLs</label>
            <textarea id="urls" name="urls" required
                placeholder="https://www.tiktok.com/@creator/video/123...">{{ old('urls') }}</textarea>

            <label for="collection_title">
                Series or collection title (optional)
            </label>
            <input id="collection_title" name="collection_title"
                value="{{ old('collection_title') }}"
                placeholder="e.g. My drama series">

            <button type="submit">Import videos</button>
        </form>

        @if (session('import_results'))
            <hr>
            <h2>Import results</h2>

            @foreach (session('import_results') as $result)
                <p class="{{ $result['status'] }}">
                    <strong>{{ strtoupper($result['status']) }}</strong> —
                    {{ $result['title'] ?? $result['url'] }}:
                    {{ $result['message'] }}
                </p>
            @endforeach
        @endif
    </div>
</body>
</html>
