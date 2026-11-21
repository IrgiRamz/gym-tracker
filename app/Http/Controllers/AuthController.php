<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required'],
        ], [
            'login.required' => 'Email atau nama wajib diisi ya.',
            'password.required' => 'Password wajib diisi ya.',
        ]);

        $login = trim($data['login']);
        $ok = false;

        // Masuk dengan email, atau dengan nama (kolom users.name). Nama tidak unik, jadi
        // semua akun bernama sama dicoba satu per satu lewat emailnya.
        // remember = true secara default agar PT tidak perlu login berulang kali.
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $ok = Auth::attempt(['email' => $login, 'password' => $data['password']], true);
        } else {
            foreach (User::where('name', $login)->get() as $user) {
                if (Auth::attempt(['email' => $user->email, 'password' => $data['password']], true)) {
                    $ok = true;
                    break;
                }
            }
        }

        if ($ok) {
            $request->session()->regenerate();
            return redirect()->intended('/');
        }

        return back()->withErrors(['login' => 'Email/nama atau password belum cocok. Coba cek lagi ya 💕'])->onlyInput('login');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
