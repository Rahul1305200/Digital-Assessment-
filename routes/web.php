<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

// Study API. The same address and ?action= scheme as the first version.
Route::match(['get', 'post'], '/api.php', ApiController::class);

// The two shared patient links. Patients scan one of them, then type their study code.
Route::get('/{entry}', fn () => response()->file(resource_path('app.html'), ['Content-Type' => 'text/html; charset=utf-8']))->whereIn('entry', ['music', 'no-music']);

// The patient and staff screens (plain HTML, CSS and JavaScript) live in resources/app.html.
Route::get('/', fn () => response()->file(resource_path('app.html'), ['Content-Type' => 'text/html; charset=utf-8']));
