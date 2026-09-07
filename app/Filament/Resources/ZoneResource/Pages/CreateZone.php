<?php

namespace App\Filament\Resources\ZoneResource\Pages;

use App\Filament\Resources\ZoneResource;
use App\Models\ZoneSurgeSlot;
use App\Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use MatanYadaev\EloquentSpatial\Objects\LineString;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Objects\Polygon;

class CreateZone extends CreateRecord
{
    protected static string $resource = ZoneResource::class;


    public ?string $mapBoundaries = null;


    protected array $dayFields = [
        0 => 'surge_slots_day_0', // Sunday
        1 => 'surge_slots_day_1', // Monday
        2 => 'surge_slots_day_2', // Tuesday
        3 => 'surge_slots_day_3', // Wednesday
        4 => 'surge_slots_day_4', // Thursday
        5 => 'surge_slots_day_5', // Friday
        6 => 'surge_slots_day_6', // Saturday
    ];


    #[On('boundaries-updated')]
    public function onBoundariesUpdated(string $boundaries): void
    {

        $this->mapBoundaries = $boundaries;
    }


    public function updatedMapBoundaries(?string $value): void
    {
        if ($value) {
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {

        // Get boundaries JSON string - we'll save it separately using raw SQL
        $boundariesJson = null;

        if (empty($data['boundaries']) || $data['boundaries'] === null || $data['boundaries'] === '' || $data['boundaries'] === '[]') {
            if (!empty($this->mapBoundaries) && $this->mapBoundaries !== '[]') {
                $boundariesJson = $this->mapBoundaries;
            } elseif ($boundariesData = request()->input('boundaries')) {
                $boundariesJson = $boundariesData;
            } elseif ($boundariesData = request()->input('map_boundaries')) {
                $boundariesJson = $boundariesData;
            }
        } else {
            // If boundaries is already set, check if it's a string or needs conversion
            if (is_string($data['boundaries'])) {
                $boundariesJson = $data['boundaries'];
            } elseif (is_array($data['boundaries'])) {
                $boundariesJson = json_encode($data['boundaries']);
            }
        }

        // Store boundaries JSON for later use in afterCreate
        if ($boundariesJson) {
            $this->mapBoundaries = $boundariesJson;
        }

        // Remove boundaries from data array - we'll save it separately using raw SQL
        // This prevents the Polygon object serialization issue
        unset($data['boundaries']);

        // Remove surge slot fields from data array - they're not database columns
        // and will be handled separately in afterCreate()
        foreach ($this->dayFields as $fieldName) {
            unset($data[$fieldName]);
        }

        return $data;
    }


    protected function convertBoundariesToPolygon(mixed $boundaries): ?Polygon
    {
        if ($boundaries instanceof Polygon) {
            return $boundaries;
        }

        $pointsArray = null;

        if (is_string($boundaries)) {
            $decoded = json_decode($boundaries, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $pointsArray = $decoded;
            }
        } elseif (is_array($boundaries)) {
            $pointsArray = $boundaries;
        }

        if (!is_array($pointsArray) || count($pointsArray) < 3) {
            return null;
        }

        try {
            $points = array_map(function ($point) {
                return new Point((float) $point['lat'], (float) $point['lng']);
            }, $pointsArray);

            if (
                $points[0]->latitude !== $points[count($points) - 1]->latitude ||
                $points[0]->longitude !== $points[count($points) - 1]->longitude
            ) {
                $points[] = $points[0];
            }

            return new Polygon([new LineString($points)]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function beforeCreate(): void
    {

        $data = $this->form->getState();

        if (isset($data['status']) && $data['status'] === true && isset($data['city_id'])) {
            $city = \App\Models\City::find($data['city_id']);

            if ($city && !$city->status) {
                throw ValidationException::withMessages([
                    'status' => 'City is inactive. Cannot create active zone in an inactive city.'
                ]);
            }
        }
    }

    protected function afterCreate(): void
    {
        // Save boundaries using raw SQL to avoid Polygon serialization issues
        $this->saveBoundaries();

        // Save surge slots
        $this->saveSurgeSlots();
    }

    protected function saveBoundaries(): void
    {
        if (empty($this->mapBoundaries) || $this->mapBoundaries === '[]' || $this->mapBoundaries === 'null') {

            return;
        }

        try {
            $points = json_decode($this->mapBoundaries, true);

            if (!$points || !is_array($points) || count($points) < 3) {
                return;
            }


            // Convert to WKT format (Well-Known Text)
            $wktPoints = [];
            foreach ($points as $point) {
                if (!isset($point['lat']) || !isset($point['lng'])) {

                    return;
                }
                $wktPoints[] = (float) $point['lng'] . ' ' . (float) $point['lat'];
            }

            // Close the polygon by adding the first point at the end
            if (count($wktPoints) > 0) {
                $wktPoints[] = $wktPoints[0];
            }

            $wkt = 'POLYGON((' . implode(', ', $wktPoints) . '))';


            // Update boundaries using raw SQL
            $affectedRows = DB::update(
                "UPDATE zones SET boundaries = ST_GeomFromText(?), updated_at = NOW() WHERE id = ?",
                [$wkt, $this->record->id]
            );


            // Verify the boundaries were saved correctly
            $verification = DB::selectOne(
                "SELECT ST_AsText(boundaries) as boundaries_text FROM zones WHERE id = ?",
                [$this->record->id]
            );
        } catch (\Exception $e) {
            throw $e; // Re-throw to prevent zone creation without boundaries
        }
    }


    protected function saveSurgeSlots(): void
    {
        $data = $this->form->getState();
        $zoneId = $this->record->id;

        foreach ($this->dayFields as $dayOfWeek => $fieldName) {
            $slots = $data[$fieldName] ?? [];

            foreach ($slots as $slot) {
                ZoneSurgeSlot::create([
                    'zone_id' => $zoneId,
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'surge_multiplier' => $slot['surge_multiplier'],
                    'is_active' => $slot['is_active'] ?? true,
                ]);
            }
        }
    }
}
