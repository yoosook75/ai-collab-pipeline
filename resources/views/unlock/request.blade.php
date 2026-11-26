@extends('layout')
@section('title', '본인 확인')
@section('content')
<h1>{{ $purpose === 'posting' ? '글쓰기 제한 해제' : '계정 잠금 해제' }}</h1>
<p>가입한 이메일을 입력하면 확인 코드를 보내 드립니다.</p>
<form method="POST" action="{{ route('unlock.request') }}">
    @csrf
    <input type="hidden" name="purpose" value="{{ $purpose }}">
    <label>이메일 <input type="email" name="email" value="{{ old('email', auth()->user()?->email) }}" required></label>
    <p><button>코드 받기</button></p>
</form>
@endsection
