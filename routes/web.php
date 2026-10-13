<?php

use App\Http\Controllers\AiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth'])->group(function () {
    // Os dois fluxos usam o mesmo controller e preservam os nomes públicos das rotas.
    Route::match(['get', 'post'], 'bibliografia', [AiController::class, 'bibliografia'])->name('bibliografia.bibliografia');
    Route::match(['get', 'post'], 'ementa', [AiController::class, 'ementa'])->name('bibliografia.ementa');
});

// Permite usar Gate::check('user')na view 404
Route::fallback(function () {
    return view('errors.404');
});
