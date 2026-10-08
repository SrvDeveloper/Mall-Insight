<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:create {email : ログインに使うメールアドレス} {name : 画面に表示する名前}')]
#[Description('ログインできる利用者を登録する。パスワードは画面に出さずに入力する（利用者の管理画面は B-117 で作る）')]
class CreateUser extends Command
{
    use AsksForPassword;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) $this->argument('name');
        if (! $this->validates(['email' => $email, 'name' => $name], ['email' => ['required', 'email', 'max:255', 'unique:users,email'], 'name' => ['required', 'string', 'max:255']])) {
            return self::FAILURE;
        }

        $password = $this->askForPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        User::create(['email' => $email, 'name' => $name, 'password' => $password]);
        $this->info("{$email}（{$name}）を登録しました。");

        return self::SUCCESS;
    }
}
