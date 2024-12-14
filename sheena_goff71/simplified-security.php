<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class SecurityController extends Controller
{
    // ثبت نام سریع کاربر
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password)
        ]);

        Auth::login($user);
        return redirect('/dashboard');
    }

    // لاگین سریع
    public function login(Request $request)  
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (Auth::attempt($credentials)) {
            return redirect('/dashboard');
        }

        return back()->withErrors([
            'email' => 'اطلاعات وارد شده صحیح نیست.'
        ]);
    }

    // بررسی دسترسی پایه
    public function checkAccess(Request $request, $resource)
    {
        // بررسی ساده مجوز دسترسی
        if (!Auth::check()) {
            return false;
        }

        $user = Auth::user();
        return $user->hasPermission($resource);
    }

    // لاگ عملیات های مهم
    protected function auditLog($action, $data = [])
    {
        \Log::info($action, [
            'user' => Auth::id(),
            'ip' => request()->ip(),
            'data' => $data
        ]);
    }
}
