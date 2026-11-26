@extends('layout')
@section('title', '회원가입')
@section('content')
<h1>회원가입</h1>
<form method="POST" action="{{ route('register') }}">
    @csrf
    <label>이름 <input name="name" value="{{ old('name') }}" required></label>
    <label>이메일 <input type="email" name="email" value="{{ old('email') }}" required></label>
    <label>비밀번호 <input type="password" name="password" required></label>
    <label>비밀번호 확인 <input type="password" name="password_confirmation" required></label>
    <p><button>가입하기</button></p>
</form>
@endsection
