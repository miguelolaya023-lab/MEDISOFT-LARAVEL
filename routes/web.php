<?php

use App\Http\Controllers\PacienteController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->middleware('verified')->name('dashboard');
    Route::get('/pacientes', [PacienteController::class, 'index'])->name('pacientes.index');
    Route::get('/pacientes/buscar', [PacienteController::class, 'search'])->name('pacientes.search');
    Route::get('/pacientes/create', [PacienteController::class, 'create'])->can('create', Paciente::class)->name('pacientes.create');
    Route::post('/pacientes', [PacienteController::class, 'store'])->name('pacientes.store');
    Route::get('/pacientes/{paciente}', [PacienteController::class, 'show'])->can('view', 'paciente')->name('pacientes.show');
    Route::get('/usuarios', [UserController::class, 'index'])->can('viewAny', User::class)->name('usuarios.index');
    Route::get('/usuarios/create', [UserController::class, 'create'])->can('create', User::class)->name('usuarios.create');
    Route::post('/usuarios', [UserController::class, 'store'])->name('usuarios.store');
    Route::get('/usuarios/{user}', [UserController::class, 'show'])->can('view', 'user')->name('usuarios.show');
    Route::get('/usuarios/{user}/edit', [UserController::class, 'edit'])->can('update', 'user')->name('usuarios.edit');
    Route::match(['put', 'patch'], '/usuarios/{user}', [UserController::class, 'update'])->name('usuarios.update');
    Route::patch('/usuarios/{user}/inactivar', [UserController::class, 'inactivate'])->name('usuarios.inactivate');
    Route::patch('/usuarios/{user}/activar', [UserController::class, 'activate'])->name('usuarios.activate');
    Route::patch('/usuarios/{user}/rol', [UserController::class, 'assignRole'])->name('usuarios.assign-role');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});
require __DIR__.'/auth.php';
