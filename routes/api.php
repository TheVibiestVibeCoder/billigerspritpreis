<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::get('/stations', [ApiController::class, 'stations'])->name('api.stations');
Route::get('/austria-boundary', [ApiController::class, 'austriaBoundary'])->name('api.austria-boundary');
