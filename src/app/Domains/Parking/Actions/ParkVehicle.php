<?php

namespace App\Domains\Parking\Actions;

use App\Domains\Parking\Data\ParkVehicleData;
use App\Domains\Parking\Data\VehicleType;
use App\Domains\Parking\Exceptions\VehicleAlreadyParkedException;
use App\Domains\Parking\Exceptions\VehicleTypeMismatchException;
use App\Domains\Parking\Models\ParkingSession;
use App\Domains\Parking\Models\Spot;
use App\Domains\Parking\Models\Vehicle;
use App\Domains\Parking\Services\SpotAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ParkVehicle
{
    // Manual namespace for pg_advisory_xact_lock. Bump if other features
    // start using advisory locks and risk colliding on the same (ns, id) key.
    private const int VAN_LOCK_NAMESPACE = 1;

    public function __construct(
        private readonly SpotAllocator $spotAllocator,
    ) {}

    public function handle(ParkVehicleData $data): ParkingSession
    {
        try {
            return DB::transaction(function () use ($data): ParkingSession {
                $vehicle = Vehicle::createOrFirst(
                    [
                        'parking_lot_id' => $data->parkingLotId,
                        'license_plate' => $data->licensePlate,
                    ],
                    ['type' => $data->vehicleType],
                );

                if ($vehicle->type !== $data->vehicleType) {
                    throw new VehicleTypeMismatchException(
                        $vehicle->type,
                        $data->vehicleType,
                    );
                }

                // Vans need the per-lot advisory lock to make consecutive-spot
                // allocation atomic across concurrent requests. Cars and
                // motorcycles rely on SELECT ... FOR UPDATE against the partial
                // indexes plus the parkings_active_vehicle_unique constraint.
                if ($data->vehicleType === VehicleType::Van) {
                    DB::statement(
                        'SELECT pg_advisory_xact_lock(?, ?)',
                        [self::VAN_LOCK_NAMESPACE, $data->parkingLotId],
                    );
                }

                $spotIds = $this->spotAllocator->allocate(
                    $data->parkingLotId,
                    $data->vehicleType,
                );

                // Duplicate active parkings are prevented by the
                // `parkings_active_vehicle_unique` partial index; a concurrent
                // INSERT throws UniqueConstraintViolationException, caught below.
                // The entire transaction (vehicle upsert + spot reservation) is
                // rolled back when that happens.
                $parking = ParkingSession::create([
                    'parking_lot_id' => $data->parkingLotId,
                    'vehicle_id' => $vehicle->id,
                    'started_at' => now(),
                ]);

                Spot::whereIn('id', $spotIds)
                    ->update(['parking_id' => $parking->id]);

                return $parking->load(['vehicle', 'spots']);
            });
        } catch (UniqueConstraintViolationException) {
            throw new VehicleAlreadyParkedException;
        }
    }
}
