<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Security\UnlockCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UnlockController extends Controller
{
    public function __construct(private UnlockCodes $codes) {}

    public function create(Request $request): View
    {
        return view('unlock.request', ['purpose' => $this->purpose($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'purpose' => ['required', Rule::in(UnlockCodes::PURPOSES)],
        ]);

        $user = User::where('email', $data['email'])->first();
        $restricted = $user && ($data['purpose'] === 'login' ? $user->locked_at : $user->posting_restricted_at);
        if ($restricted) {
            $this->codes->issue($user, $data['purpose']);
        }

        // Same response whether or not the account exists or is restricted.
        return redirect()->route('unlock.verify', ['purpose' => $data['purpose']])
            ->with('status', '해당 계정이 제한 상태라면 이메일로 확인 코드를 보냈습니다.')
            ->withInput($request->only('email'));
    }

    public function edit(Request $request): View
    {
        return view('unlock.verify', ['purpose' => $this->purpose($request)]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'purpose' => ['required', Rule::in(UnlockCodes::PURPOSES)],
            'code' => ['required', 'digits:6'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $this->codes->verify($user, $data['purpose'], $data['code'])) {
            return back()->withErrors(['code' => '코드가 올바르지 않거나 만료되었습니다.'])->onlyInput('email');
        }

        return redirect()->route($data['purpose'] === 'login' ? 'login' : 'posts.index')
            ->with('status', '제한이 해제되었습니다.');
    }

    private function purpose(Request $request): string
    {
        $purpose = $request->query('purpose');

        return in_array($purpose, UnlockCodes::PURPOSES, true) ? $purpose : 'login';
    }
}
