<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateUser extends Command
{
    protected $signature = 'user:create {email} {--name=} {--password=}';

    protected $description = 'Crée un compte (ou change son mot de passe). Il n\'y a pas d\'inscription publique.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->option('password') ?: $this->secret('Mot de passe');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => 'required|email', 'password' => ['required', Password::min(10)]],
        );
        if ($validator->fails()) {
            $this->error(implode(' ', $validator->errors()->all()));

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->name = $this->option('name') ?: ($user->name ?: strstr($email, '@', true));
        $user->password = $password;
        $user->save();

        $this->info(($user->wasRecentlyCreated ? 'Compte créé : ' : 'Compte mis à jour : ').$email);

        return self::SUCCESS;
    }
}
