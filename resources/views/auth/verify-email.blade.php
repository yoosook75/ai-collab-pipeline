@extends('layout')
@section('title', '이메일 인증')
@section('content')
<h1>이메일 인증이 필요합니다</h1>
<p>가입하신 이메일로 보낸 인증 링크를 눌러 주세요.</p>
<form method="POST" action="{{ route('verification.send') }}">@csrf<button>인증 메일 다시 받기</button></form>
@endsection
