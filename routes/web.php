<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/forgot-password', 'welcome');
Route::view('/reset-password/{token}', 'welcome')->name('password.reset');
