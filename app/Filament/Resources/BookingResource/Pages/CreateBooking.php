<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Models\Zone;
use App\Filament\Resources\BookingResource;
use Filament\Actions;
use App\Filament\Resources\Pages\CreateRecord;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    public $pickup_location = '';
    public $dropoff_location = '';
    public $pickup_zone_id = null;
    public $dropoff_zone_id = null;
    public $pickup_latitude = null;
    public $pickup_longitude = null;
    public $dropoff_latitude = null;
    public $dropoff_longitude = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['booking_code'] = 'BK' . strtoupper(uniqid());

        $data['otp'] = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);

        if (!empty($this->pickup_latitude) && !empty($this->pickup_longitude)) {
            $data['pickup_latitude'] = (float) $this->pickup_latitude;
            $data['pickup_longitude'] = (float) $this->pickup_longitude;

            $data['pickup_location'] = "POINT({$this->pickup_longitude} {$this->pickup_latitude})";
        } elseif (!empty($this->pickup_location)) {

            $coords = $this->parseLocationString($this->pickup_location);
            $data['pickup_latitude'] = $coords['lat'];
            $data['pickup_longitude'] = $coords['lng'];
            $data['pickup_location'] = "POINT({$coords['lng']} {$coords['lat']})";
        } else {

            $data['pickup_latitude'] = 0;
            $data['pickup_longitude'] = 0;
            $data['pickup_location'] = 'POINT(0 0)';
        }

        if (!empty($this->dropoff_latitude) && !empty($this->dropoff_longitude)) {
            $data['dropoff_latitude'] = (float) $this->dropoff_latitude;
            $data['dropoff_longitude'] = (float) $this->dropoff_longitude;

            $data['dropoff_location'] = "POINT({$this->dropoff_longitude} {$this->dropoff_latitude})";
        } elseif (!empty($this->dropoff_location)) {

            $coords = $this->parseLocationString($this->dropoff_location);
            $data['dropoff_latitude'] = $coords['lat'];
            $data['dropoff_longitude'] = $coords['lng'];
            $data['dropoff_location'] = "POINT({$coords['lng']} {$coords['lat']})";
        } else {

            $data['dropoff_latitude'] = 0;
            $data['dropoff_longitude'] = 0;
            $data['dropoff_location'] = 'POINT(0 0)';
        }

        if (!empty($this->pickup_zone_id)) {
            $data['pickup_zone_id'] = $this->resolveZoneId($this->pickup_zone_id);
        }

        if (!empty($this->dropoff_zone_id)) {
            $data['dropoff_zone_id'] = $this->resolveZoneId($this->dropoff_zone_id);
        }

        if (empty($data['pickup_address'])) {
            $data['pickup_address'] = 'Default Pickup Address';
        }

        if (empty($data['dropoff_address'])) {
            $data['dropoff_address'] = 'Default Dropoff Address';
        }

        if (empty($data['payment_status'])) {
            $data['payment_status'] = 'pending';
        }

        $data['meta_data'] = array_merge($data['meta_data'] ?? [], [
            'created_by_admin' => true,
            'created_by_admin_id' => auth()->id(),
        ]);

        return $data;
    }

    
    private function parseLocationString(string $location): array
    {

        if (str_contains($location, ',')) {
            $parts = explode(',', $location);
            if (count($parts) === 2) {
                $lat = (float) trim($parts[0]);
                $lng = (float) trim($parts[1]);
                return ['lat' => $lat, 'lng' => $lng];
            }
        }

        return ['lat' => 0, 'lng' => 0];
    }

    
    private function formatLocationToPoint(string $location): string
    {

        if (str_starts_with($location, 'POINT(')) {
            return $location;
        }

        if (str_contains($location, ',')) {
            $parts = explode(',', $location);
            if (count($parts) === 2) {
                $lat = trim($parts[0]);
                $lng = trim($parts[1]);
                return "POINT({$lng} {$lat})"; // Note: POINT format is (longitude latitude)
            }
        }

        return 'POINT(0 0)';
    }

    
    private function resolveZoneId($zoneValue)
    {

        if (is_numeric($zoneValue)) {
            return (int) $zoneValue;
        }

        if (is_string($zoneValue)) {
            $zone = Zone::where('name', 'like', '%' . $zoneValue . '%')
                ->orWhere('name', $zoneValue)
                ->first();

            if ($zone) {
                return $zone->id;
            }


            $firstZone = Zone::first();
            return $firstZone ? $firstZone->id : null;
        }

        return null;
    }
}
