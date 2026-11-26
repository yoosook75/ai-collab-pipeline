@extends('layout')
@section('title', '확인 코드 입력')
@section('content')
<h1>확인 코드 입력</h1>
<form method="POST" action="{{ route('unlock.verify') }}">
    @csrf
    <input type="hidden" name="purpose" value="{{ $purpose }}">
    <label>이메일 <input type="email" name="email" value="{{ old('email', auth()->user()?->email) }}" required></label>
    <label>6자리 코드 <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label>
    <p><button>해제하기</button></p>
</form>
@endsection
