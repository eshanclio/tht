<?php

use App\Http\Controllers\ParkingController;
use Illuminate\Support\Facades\Route;

Route::post('/parking-lots/{parkingLot}/sessions', [ParkingController::class, 'park'])
    ->name('parking.park');

Route::delete('/parking-lots/{parkingLot}/vehicles/{licensePlate}', [ParkingController::class, 'unpark'])
    ->name('parking.unpark');

Route::get('/parking-lots/{parkingLot}/availability', [ParkingController::class, 'availability'])
    ->name('parking.availability');
