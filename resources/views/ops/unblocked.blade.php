@extends('layout')
@section('title', '차단 해제')
@section('content')
<div style="text-align:center; padding:2rem 0;">
    <h1 style="font-size:1.75rem; margin:0 0 1rem;">차단을 해제했습니다</h1>
    <p style="font-size:1.1rem; word-break:break-all;"><strong>{{ $ip }}</strong></p>
    <p>이 IP 주소에서 다시 게시판에 접속할 수 있습니다.</p>
    <p><a href="{{ route('posts.index') }}" style="display:inline-block; padding:.75rem 1.5rem; background:#2c3e50; color:#fff; border-radius:6px; text-decoration:none;">게시판으로 돌아가기</a></p>
</div>
@endsection
