<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * 利用者のコマンドで、パスワードを画面に出さずに2回入力してもらう。
 *
 * @mixin Command
 */
trait AsksForPassword
{
    /**
     * 8文字以上で、2回の入力が同じならパスワードを返す。違えば理由を表示して null を返す。
     */
    private function askForPassword(): ?string
    {
        $password = (string) $this->secret('パスワード（8文字以上）');
        $confirmation = (string) $this->secret('パスワード（確認のためもう一度）');

        return $this->validates(['password' => $password, 'password_confirmation' => $confirmation], ['password' => ['required', 'confirmed', Password::min(8)]]) ? $password : null;
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, mixed>  $rules
     */
    private function validates(array $data, array $rules): bool
    {
        $validator = Validator::make($data, $rules, [], ['email' => 'メールアドレス', 'name' => '名前', 'password' => 'パスワード']);
        foreach ($validator->errors()->all() as $message) {
            $this->error($message);
        }

        return $validator->passes();
    }
}
