<?php

namespace App\Domains\Parking\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VehicleNotParkedException extends Exception
{
    public function __construct(string $message = 'Vehicle is not currently parked in this lot.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], Response::HTTP_NOT_FOUND);
    }
}
