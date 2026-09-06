<?php

declare(strict_types=1);

namespace Rgesn\Controllers;

use Rgesn\Support\Auth;
use Rgesn\Support\Csrf;
use Rgesn\Support\Http;
use Rgesn\Support\View;

final class AuthController
{
    public function form(): void
    {
        if (Auth::isLoggedIn()) {
            Http::redirect('/projects');
        }

        echo View::render('auth/login.html.twig', ['csrf' => Csrf::token()]);
    }

    public function attempt(): void
    {
        if (!Csrf::check() || !Auth::attempt(Http::input('password'))) {
            Http::flash('error', 'Mot de passe incorrect.');
            Http::redirect('/login');
        }

        Http::redirect('/projects');
    }

    public function logout(): void
    {
        Auth::logout();
        Http::redirect('/login');
    }
}
