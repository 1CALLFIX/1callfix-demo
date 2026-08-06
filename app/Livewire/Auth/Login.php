<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';
    public string $password = '';
    public string $error = '';

    public function submit()
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            $this->error = 'Invalid email or password.';
            return;
        }

        if (Auth::user()->role !== 'super_admin') {
            Auth::logout();
            $this->error = 'This account does not have admin access.';
            return;
        }

        session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    public function render()
    {
        return view('livewire.auth.login')
            ->layout('layouts.admin', ['title' => 'Admin Login']);
    }
}
