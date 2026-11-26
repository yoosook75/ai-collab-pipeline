<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Security\SpamGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PostController extends Controller
{
    public function index(): View
    {
        return view('posts.index', [
            'posts' => Post::visible()->with('user')->latest()->latest('id')->paginate(10),
        ]);
    }

    public function create(): View
    {
        return view('posts.create');
    }

    public function store(Request $request, SpamGuard $spamGuard): RedirectResponse
    {
        $user = $request->user();
        if ($user->posting_restricted_at && ! $user->is_admin) {
            return redirect()->route('unlock.request', ['purpose' => 'posting'])
                ->withErrors(['body' => '스팸 의심으로 글쓰기가 제한되었습니다. 이메일 인증으로 해제하세요.']);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $post = $user->posts()->create([
            ...$data,
            'body_hash' => hash('sha256', $data['body']),
        ]);

        if ($spamGuard->inspect($user, $post)) {
            return redirect()->route('unlock.request', ['purpose' => 'posting'])
                ->withErrors(['body' => '스팸 의심으로 글쓰기가 제한되었습니다. 이메일 인증으로 해제하세요.']);
        }

        return redirect()->route('posts.index');
    }
}
