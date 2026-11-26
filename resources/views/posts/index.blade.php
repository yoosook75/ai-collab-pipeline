@extends('layout')
@section('content')
<h1>게시글</h1>
@forelse ($posts as $post)
    <article>
        <h2>{{ $post->title }}</h2>
        <p>{{ $post->body }}</p>
        <small>{{ $post->user->name }} · {{ $post->created_at->diffForHumans() }}</small>
    </article>
@empty
    <p>아직 글이 없습니다.</p>
@endforelse
@endsection
