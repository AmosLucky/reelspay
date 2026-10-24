@extends('admin.layout')

@section('title', 'Edit video')

@push('styles')
<style>
        .panel { background: #fff; padding: 24px; border-radius: 14px; box-shadow: 0 3px 15px #00000010; }
        label { display: block; margin-top: 16px; }
        input { box-sizing: border-box; width: 100%; padding: 12px; margin-top: 8px; border: 1px solid #ccc; border-radius: 8px; }
        input[type="checkbox"] { width: auto; }
        button { margin-top: 20px; background: #111; color: #fff; border: 0; padding: 13px 20px; border-radius: 8px; cursor: pointer; }
        a { color: #111; }
        .error { color: #b42318; }
    </style>
@endpush

@section('content')
    <main class="panel">
        <p><a href="{{ route('tiktok-library.index') }}">Back to video library</a></p>
        <h1>Edit video</h1>

        @if ($errors->any())
            <div class="error" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('tiktok-library.update', $video) }}">
            @csrf
            @method('PUT')

            <label for="title">Title</label>
            <input id="title" name="title" value="{{ old('title', $video->title) }}">

            <label for="author">Creator</label>
            <input id="author" name="author" value="{{ old('author', $video->author) }}">

            <label for="collection_title">Collection</label>
            <input id="collection_title" name="collection_title"
                value="{{ old('collection_title', $video->collection_title) }}">

            <label for="sort_order">Display order</label>
            <input id="sort_order" type="number" min="0" name="sort_order"
                value="{{ old('sort_order', $video->sort_order) }}" required>

            <input type="hidden" name="is_active" value="0">
            <label for="is_active">
                <input id="is_active" type="checkbox" name="is_active" value="1"
                    @checked(old('is_active', $video->is_active))>
                Show this video in the public library
            </label>

            <button type="submit">Save changes</button>
        </form>
    </main>
@endsection
