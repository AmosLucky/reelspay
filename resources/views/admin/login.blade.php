<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin login</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 440px; margin: 60px auto; padding: 0 18px; background: #f5f6fa; color: #222; }
        .panel { background: #fff; padding: 24px; border-radius: 14px; box-shadow: 0 3px 15px #00000010; }
        label { display: block; margin-top: 16px; }
        input { box-sizing: border-box; width: 100%; padding: 12px; margin-top: 8px; border: 1px solid #ccc; border-radius: 8px; }
        button { margin-top: 20px; background: #111; color: #fff; border: 0; padding: 13px 20px; border-radius: 8px; cursor: pointer; }
        .error { color: #b42318; }
    </style>
</head>
<body>
    <main class="panel">
        <h1>Admin login</h1>

        @if ($errors->any())
            <div class="error" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

            <label for="password">Password</label>
            <input id="password" type="password" name="password" required>

            <button type="submit">Log in</button>
        </form>
    </main>
</body>
</html>
