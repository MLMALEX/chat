<?php

use App\Http\Controllers\CallController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post('/calls', [CallController::class, 'start'])->name('calls.start');
    Route::post('/calls/{call}/respond', [CallController::class, 'respond'])->name('calls.respond');
    Route::post('/calls/{call}/end', [CallController::class, 'end'])->name('calls.end');
    Route::post('/calls/{call}/token', [CallController::class, 'token'])->name('calls.token');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
