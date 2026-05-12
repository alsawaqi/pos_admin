<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/login');

Route::get('/login', fn () => Inertia::render('Auth/Login'))
    ->name('login');

Route::get('/admin', fn () => Inertia::render('Admin/Dashboard'))
    ->name('admin.dashboard');
