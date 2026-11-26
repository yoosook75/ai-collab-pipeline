<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Security\SpamGuard;
use Closure;
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

        $utf8 = fn (string $attribute, mixed $value, Closure $fail) => is_string($value) && ! mb_check_encoding($value, 'UTF-8')
            ? $fail('UTF-8 인코딩이 아닌 문자가 포함되어 있습니다.')
            : null;

        $data = $request->validate([
            'title' => ['bail', 'required', 'string', $utf8, 'max:200'],
            'body' => ['bail', 'required', 'string', $utf8, 'max:10000'],
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
