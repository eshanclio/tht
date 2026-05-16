<?php

use App\Http\Controllers\ParkingController;
use Illuminate\Support\Facades\Route;

Route::post("/park", [ParkingController::class, "park"]);
Route::post("/unpark", [ParkingController::class, "unpark"]);
Route::get("/parking-lots/{parkingLot}/availability", [ParkingController::class, "availability"]);
