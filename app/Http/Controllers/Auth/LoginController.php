<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\LoginLockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(private LoginLockout $lockout) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();
        if ($user?->locked_at && ! $user->is_admin) {
            return back()->with('locked', true)
                ->withErrors(['email' => '계정이 잠겼습니다. 이메일 인증으로 해제하세요.'])->onlyInput('email');
        }

        if (! Auth::attempt($credentials)) {
            $this->lockout->recordFailure($credentials['email']);

            return back()->withErrors(['email' => '이메일 또는 비밀번호가 올바르지 않습니다.'])->onlyInput('email');
        }

        $this->lockout->clear($credentials['email']);
        $request->session()->regenerate();

        return redirect()->intended(route('posts.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('posts.index');
    }
}
