<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * ログイン・ログアウト（バックログ B-009、決定記録 K-051）。画面と同じドメインから呼ぶ前提で、Laravel のセッションで認証する。
 */
class AuthController extends Controller
{
    /**
     * ログインしている利用者。ログインしていなければ 401。
     */
    public function user(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    /**
     * ログインする。セッションIDを作り直し、ログイン前のセッションを引き継がない。
     */
    public function login(LoginRequest $request): UserResource
    {
        $request->authenticate();
        $request->session()->regenerate();

        return UserResource::make($request->user());
    }

    /**
     * ログアウトする。セッションを破棄し、CSRFトークンも作り直す。
     */
    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
