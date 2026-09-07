<?php

namespace App\Services;

use App\Models\Zone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ZoneService
{
    
    public function getZonesByCity(int $cityId): Collection
    {
        return Zone::where('city_id', $cityId)
            ->orderBy('name')
            ->get();
    }

    
    public function getZoneById(int $id): ?Zone
    {
        return Zone::findOrFail($id);
    }

    
    public function createZone(array $data): Zone
    {
        $coordinates = json_decode($data['coordinates'], true);
        if (!$this->isValidPolygon($coordinates)) {
            throw ValidationException::withMessages([
                'coordinates' => ['Invalid polygon coordinates format.'],
            ]);
        }

        $polygonText = $this->convertToPolygonText($coordinates);
        
        return DB::transaction(function () use ($data, $polygonText) {
            return Zone::create([
                'city_id' => $data['city_id'],
                'name' => $data['name'],
                'code' => $data['code'],
                'is_active' => $data['is_active'] ?? true,
                'coordinates' => DB::raw("ST_GeomFromText('$polygonText')"),
                'surge_multiplier' => $data['surge_multiplier'] ?? 1.0,
                'supported_ride_types' => $data['supported_ride_types'] ?? null,
                'pickup_allowed' => $data['pickup_allowed'] ?? true,
                'drop_allowed' => $data['drop_allowed'] ?? true,
                'driver_assignment_radius' => $data['driver_assignment_radius'] ?? 5000,
                'settings' => $data['settings'] ?? null,
            ]);
        });
    }

    
    public function updateZone(Zone $zone, array $data): Zone
    {
        $updates = [];

        foreach ([
            'name', 'code', 'is_active', 'surge_multiplier', 'supported_ride_types',
            'pickup_allowed', 'drop_allowed', 'driver_assignment_radius', 'settings'
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (isset($data['coordinates'])) {
            $coordinates = json_decode($data['coordinates'], true);
            if (!$this->isValidPolygon($coordinates)) {
                throw ValidationException::withMessages([
                    'coordinates' => ['Invalid polygon coordinates format.'],
                ]);
            }

            $polygonText = $this->convertToPolygonText($coordinates);
            $updates['coordinates'] = DB::raw("ST_GeomFromText('$polygonText')");
        }

        return DB::transaction(function () use ($zone, $updates) {
            $zone->update($updates);
            return $zone->fresh();
        });
    }

    
    public function deleteZone(Zone $zone): bool
    {
        return DB::transaction(function () use ($zone) {
            return $zone->delete();
        });
    }

    
    public function isPointInZone(Zone $zone, float $latitude, float $longitude): bool
    {
        return DB::table('zones')
            ->where('id', $zone->id)
            ->whereRaw("ST_Contains(coordinates, ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')')))", [$longitude, $latitude])
            ->exists();
    }

    
    public function getZonesContainingPoint(float $latitude, float $longitude): Collection
    {
        return Zone::whereRaw("ST_Contains(coordinates, ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')')))", [$longitude, $latitude])
            ->where('is_active', true)
            ->get();
    }

    
    public function calculateZoneArea(Zone $zone): float
    {
        $result = DB::select("
            SELECT ST_Area(
                ST_Transform(
                    coordinates,
                    4326,
                    3857
                )
            ) / 1000000 as area
            FROM zones
            WHERE id = ?
        ", [$zone->id]);

        return round($result[0]->area, 2);
    }

    
    protected function isValidPolygon(array $coordinates): bool
    {
        if (count($coordinates) < 3) {
            return false;
        }

        if ($coordinates[0] !== end($coordinates)) {
            return false;
        }

        foreach ($coordinates as $point) {
            if (!is_array($point) || count($point) !== 2 ||
                !is_numeric($point[0]) || !is_numeric($point[1])) {
                return false;
            }
        }

        return true;
    }

    
    protected function convertToPolygonText(array $coordinates): string
    {
        $points = array_map(function ($point) {
            return implode(' ', array_reverse($point)); // Reverse to lon,lat format
        }, $coordinates);

        return 'POLYGON((' . implode(',', $points) . '))';
    }
}
