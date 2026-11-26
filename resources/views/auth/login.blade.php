@extends('layout')
@section('title', '로그인')
@section('content')
<h1>로그인</h1>
<form method="POST" action="{{ route('login') }}">
    @csrf
    <label>이메일 <input type="email" name="email" value="{{ old('email') }}" required></label>
    <label>비밀번호 <input type="password" name="password" required></label>
    <p><button>로그인</button></p>
</form>
@endsection
