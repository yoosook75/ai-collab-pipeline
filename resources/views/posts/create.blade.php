@extends('layout')
@section('title', '글쓰기')
@section('content')
<h1>글쓰기</h1>
<form method="POST" action="{{ route('posts.store') }}">
    @csrf
    <label>제목 <input name="title" value="{{ old('title') }}" maxlength="200" required></label>
    <label>내용 <textarea name="body" rows="8" required>{{ old('body') }}</textarea></label>
    <p><button>등록</button></p>
</form>
@endsection
