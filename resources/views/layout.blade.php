<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '게시판') · AI Collab Pipeline</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #222; }
        nav { display: flex; gap: 1rem; align-items: center; margin-bottom: 1.5rem; }
        nav form { margin: 0; }
        .error { color: #c0392b; }
        .status { color: #27ae60; }
        label { display: block; margin-top: .75rem; }
        input, textarea { width: 100%; padding: .4rem; box-sizing: border-box; }
        article { border-bottom: 1px solid #eee; padding: .75rem 0; }
    </style>
</head>
<body>
<nav>
    <a href="{{ route('posts.index') }}"><strong>게시판</strong></a>
    @auth
        <a href="{{ route('posts.create') }}">글쓰기</a>
        <span>{{ auth()->user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">@csrf<button>로그아웃</button></form>
    @else
        <a href="{{ route('login') }}">로그인</a>
        <a href="{{ route('register') }}">회원가입</a>
    @endauth
</nav>
@if (session('status'))<p class="status">{{ session('status') }}</p>@endif
@if ($errors->any())
    <ul class="error">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
@endif
@yield('content')
</body>
</html>
