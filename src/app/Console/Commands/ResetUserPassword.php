<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:reset-password {email : 登録済みの利用者のメールアドレス}')]
#[Description('利用者のパスワードを設定し直す。パスワードは画面に出さずに入力する')]
class ResetUserPassword extends Command
{
    use AsksForPassword;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("{$email} は登録されていません。");

            return self::FAILURE;
        }

        $password = $this->askForPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $user->update(['password' => $password]);
        $this->info("{$email} のパスワードを設定し直しました。");

        return self::SUCCESS;
    }
}
