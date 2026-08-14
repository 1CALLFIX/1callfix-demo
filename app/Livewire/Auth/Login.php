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

        // Mirrors EnsureHasAdminAccess exactly — that middleware already
        // replaced the old super_admin-only gate for every /admin route
        // (see its own docblock: "anyone holding at least one
        // role_assignment gets into the panel shell"), but this screen's
        // own inline check was never updated to match, so a real Country/
        // City/Zone Admin, Franchise Owner, Operator, or Support user could
        // never even reach the middleware — they were logged back out
        // right here, before the RBAC layer that's meant to gate them ever
        // got a chance to run. Which screens/actions they can actually use
        // once inside is still enforced by AuthorizationService::can() at
        // every individual action, unchanged by this fix.
        $user = Auth::user();
        if ($user->role !== 'super_admin' && !$user->roleAssignments()->exists()) {
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
