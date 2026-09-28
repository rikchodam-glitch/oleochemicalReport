<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Autentikasi panel.
 *
 * Kolom login menerima dua bentuk isian: username (NIK untuk akun teknisi)
 * atau alamat email (akun admin). Bentuk isian dideteksi otomatis supaya akun
 * lama yang memakai email tetap bisa masuk.
 */
class LoginController extends Controller
{
    /**
     * Tampilkan halaman login.
     *
     * @return View
     */
    public function showLogin(): View
    {
        return view('auth.login');
    }

    /**
     * Proses login memakai username/NIK atau email.
     *
     * @param  Request $request Isian identitas dan password
     * @return RedirectResponse
     */
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => 'nullable|string|max:255',
            'email'    => 'nullable|string|max:255',
            'password' => 'required|string',
        ]);

        $identitas = trim((string) ($data['username'] ?? ''));

        // Dukungan kompatibilitas: form/permintaan lama mengirim kolom email.
        if ($identitas === '') {
            $identitas = trim((string) ($data['email'] ?? ''));
        }

        if ($identitas === '') {
            return back()
                ->withErrors(['username' => 'NIK atau email wajib diisi.'])
                ->onlyInput('username');
        }

        // Deteksi bentuk isian: mengandung format email -> pakai kolom email,
        // selain itu dianggap username (NIK).
        $kolom = filter_var($identitas, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $kredensial = [
            $kolom     => $identitas,
            'password' => $data['password'],
        ];

        if (Auth::attempt($kredensial, $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect()->intended('/dashboard');
        }

        return back()
            ->withErrors(['username' => 'NIK/email atau password salah.'])
            ->onlyInput('username');
    }

    /**
     * Keluar dari panel.
     *
     * @param  Request $request Request logout
     * @return RedirectResponse
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
