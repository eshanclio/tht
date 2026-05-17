<?php

namespace App\Domains\Parking\Exceptions;

use App\Domains\Parking\Data\VehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class VehicleTypeMismatchException extends RuntimeException
{
    public function __construct(
        public readonly VehicleType $recordedType,
        public readonly VehicleType $requestedType,
    ) {
        parent::__construct(
            'Vehicle type does not match the existing record.'
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'recorded_type' => $this->recordedType->value,
            'requested_type' => $this->requestedType->value,
        ], Response::HTTP_CONFLICT);
    }
}
