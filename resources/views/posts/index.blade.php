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
@if ($posts->hasPages())
    <nav class="pagination" aria-label="페이지 이동">
        @if ($posts->onFirstPage())
            <span>이전</span>
        @else
            <a href="{{ $posts->previousPageUrl() }}" rel="prev">이전</a>
        @endif
        @foreach (range(1, $posts->lastPage()) as $page)
            @if ($page === $posts->currentPage())
                <strong aria-current="page">{{ $page }}</strong>
            @else
                <a href="{{ $posts->url($page) }}">{{ $page }}</a>
            @endif
        @endforeach
        @if ($posts->hasMorePages())
            <a href="{{ $posts->nextPageUrl() }}" rel="next">다음</a>
        @else
            <span>다음</span>
        @endif
    </nav>
@endif
@endsection
