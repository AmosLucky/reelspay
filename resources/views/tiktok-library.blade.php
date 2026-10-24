
@extends('admin.layout')

@section('title', 'Videos')

@push('styles')
<style>
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
        .video-actions { display: flex; gap: 12px; align-items: center; }
        .video-actions a { color: #111; }
        .video-list { width: 100%; border-collapse: collapse; margin-top: 24px; }
        .video-list th, .video-list td { border-bottom: 1px solid #e5e7eb; padding: 12px 8px; text-align: left; }
        .video-list-wrapper { overflow-x: auto; }
        .video-thumbnail { width: 72px; height: 96px; object-fit: cover; border-radius: 8px; background: #f5f6fa; }
        .video-list td.numeric, .video-list th.numeric { text-align: right; white-space: nowrap; }
        .season-row { background: #f5f6fa; }
        .episode-details { margin-top: 8px; }
        .episode-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .episode-table th, .episode-table td { border-bottom: 1px solid #e5e7eb; padding: 8px; text-align: left; }
        .video-actions form { margin: 0; }
        .video-actions button { padding: 8px 12px; background: #b42318; }
        .status { color: #087443; }
        .pagination { margin-top: 18px; }
    </style>
@endpush

@section('content')
    <div class="panel">
        <h1>TikTok Library</h1>
        <p>Paste TikTok links, one per line or separated by commas.</p>

        @if (session('status'))
            <p class="status">{{ session('status') }}</p>
        @endif

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

        <hr>
        <h2>Uploaded videos</h2>
        @if ($videoGroups->isEmpty())
            <p>No videos have been imported yet.</p>
        @else
            <div class="video-list-wrapper">
                <table class="video-list">
                    <thead>
                        <tr>
                            <th>Thumbnail</th>
                            <th>Movie / season</th>
                            <th>Episodes</th>
                            <th>Manage episodes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($videoGroups as $group)
                            @php($representative = $group['representative'])
                            <tr class="season-row">
                                <td>
                                    @if ($representative?->thumbnail_display_url)
                                        <img class="video-thumbnail"
                                            src="{{ $representative->thumbnail_display_url }}"
                                            alt="{{ $representative->title ?: 'Video thumbnail' }}"
                                            loading="lazy">
                                    @else
                                        <span>No thumbnail</span>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ $group['is_standalone'] ? ($representative->title ?: 'Untitled video') : $group['title'] }}</strong>
                                    @if (!$group['is_standalone'] && $representative?->title)
                                        <br><small>{{ $representative->title }}</small>
                                    @endif
                                </td>
                                <td>{{ $group['episode_count'] }}</td>
                                <td>
                                    <details class="episode-details">
                                        <summary>{{ $group['is_standalone'] ? 'Manage video' : 'View episodes' }}</summary>
                                        <table class="episode-table">
                                            <thead>
                                                <tr>
                                                    <th>Episode</th>
                                                    <th>Creator</th>
                                                    <th>Views</th>
                                                    <th>Likes</th>
                                                    <th>Comments</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($group['episodes'] as $episode)
                                                    <tr>
                                                        <td>
                                                            {{ $episode->episode_number ? 'Episode '.$episode->episode_number.' — ' : '' }}
                                                            {{ $episode->title ?: 'Untitled video' }}
                                                        </td>
                                                        <td>{{ $episode->author }}</td>
                                                        <td>{{ number_format($episode->views_count) }}</td>
                                                        <td>{{ number_format($episode->likes_count) }}</td>
                                                        <td>{{ number_format($episode->comments_count) }}</td>
                                                        <td>{{ $episode->is_active ? 'Active' : 'Hidden' }}</td>
                                                        <td>
                                                            <div class="video-actions">
                                                                <a href="{{ route('tiktok-library.edit', $episode) }}">Edit</a>
                                                                <form method="POST" action="{{ route('tiktok-library.destroy', $episode) }}"
                                                                    onsubmit="return confirm('Delete this video and its stored files?')">
                                                                    @csrf
                                                                    @method('DELETE')
                                                                    <button type="submit">Delete</button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination">{{ $videoGroups->links() }}</div>
        @endif
    </div>
@endsection
