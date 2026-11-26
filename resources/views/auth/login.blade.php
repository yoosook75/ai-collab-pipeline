@extends('layout')
@section('title', '로그인')
@section('content')
<h1>로그인</h1>
@isset($throttled)
    <p class="error">시도가 너무 많습니다. 1분 뒤에 다시 시도하세요.</p>
@endisset
@if (session('locked'))
    <p><a href="{{ route('unlock.request', ['purpose' => 'login']) }}">이메일 인증으로 잠금 해제하기</a></p>
@endif
<form method="POST" action="{{ route('login') }}">
    @csrf
    <label>이메일 <input type="email" name="email" value="{{ old('email') }}" required></label>
    <label>비밀번호 <input type="password" name="password" required></label>
    <p><button>로그인</button></p>
</form>
<p><a href="{{ route('unlock.request', ['purpose' => 'login']) }}">계정이 잠겼나요?</a></p>
@endsection
