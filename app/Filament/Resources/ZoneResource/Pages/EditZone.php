<?php

namespace App\Filament\Resources\ZoneResource\Pages;

use App\Filament\Resources\ZoneResource;
use App\Models\ZoneSurgeSlot;
use Filament\Actions;
use App\Filament\Resources\Pages\EditRecord;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class EditZone extends EditRecord
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

    protected function authorizeAccess(): void
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        $record = $this->getRecord();

        // Prevent user id 2 from editing zone with id 1
        if ($user && $user->id === 2 && $record->id === 1) {
            abort(403, 'You do not have permission to edit this zone.');
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        $record = $this->getRecord();

        // Disable form if user id 2 is editing restricted record
        if ($user && $user->id === 2 && $record->id === 1) {
            $this->form->disabled();
        }

        if (isset($data['boundaries']) && $data['boundaries']) {
            $polygon = $data['boundaries'];

            if (is_array($polygon)) {
                $coordinatesCount = count($polygon);

                if ($coordinatesCount >= 3) {
                    $jsonData = json_encode($polygon);

                    $data['boundaries'] = $jsonData;
                    return $data;
                }


                $polygon = $this->record->boundaries;
            }

            if (is_string($polygon)) {
                return $data;
            }
            $coordinates = [];

            if ($polygon instanceof \MatanYadaev\EloquentSpatial\Objects\Polygon) {
                $geometries = $polygon->getGeometries();
                if (!empty($geometries)) {
                    $linestring = $geometries[0];
                    $points = $linestring->getGeometries();

                    foreach ($points as $point) {
                        $coordinates[] = [
                            'lat' => $point->latitude,
                            'lng' => $point->longitude
                        ];
                    }

                    if (
                        count($coordinates) > 1 &&
                        $coordinates[0]['lat'] == $coordinates[count($coordinates) - 1]['lat'] &&
                        $coordinates[0]['lng'] == $coordinates[count($coordinates) - 1]['lng']
                    ) {
                        array_pop($coordinates);
                    }

                    $jsonData = json_encode($coordinates);
                    $data['boundaries'] = $jsonData;
                } else {
                }
            } else {
            }
        } else {
        }

        return $data;
    }


    protected function jsonToPolygon(string $json): ?\MatanYadaev\EloquentSpatial\Objects\Polygon
    {
        try {
            $points = json_decode($json, true);
            if ($points && is_array($points) && count($points) >= 3) {
                $coordinates = array_map(function ($point) {
                    return new \MatanYadaev\EloquentSpatial\Objects\Point($point['lat'], $point['lng']);
                }, $points);

                $coordinates[] = $coordinates[0];

                return new \MatanYadaev\EloquentSpatial\Objects\Polygon([
                    new \MatanYadaev\EloquentSpatial\Objects\LineString($coordinates)
                ]);
            }
        } catch (\Exception $e) {
            // Error handling
        }
        return null;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {

        if (!empty($this->mapBoundaries) && $this->mapBoundaries !== '[]' && $this->mapBoundaries !== 'null') {
            $polygon = $this->jsonToPolygon($this->mapBoundaries);
            if ($polygon) {
                $data['boundaries'] = $polygon;

                return $data;
            }
        }

        if (empty($data['boundaries']) || $data['boundaries'] === null || $data['boundaries'] === '' || $data['boundaries'] === '[]') {
            $boundariesData = null;

            if (!empty($this->mapBoundaries) && $this->mapBoundaries !== '[]') {
                $boundariesData = $this->mapBoundaries;
            }

            if (empty($boundariesData) || $boundariesData === '[]') {
                $boundariesData = request()->input('boundaries');
                if (empty($boundariesData) || $boundariesData === '[]') {
                    $boundariesData = request()->input('map_boundaries');
                }
                if (!empty($boundariesData) && $boundariesData !== '[]') {
                }
            }

            if (empty($boundariesData) || $boundariesData === '[]') {
                $components = request()->input('components');
                if (is_array($components)) {
                    foreach ($components as $component) {

                        if (isset($component['snapshot']['memo']['data']['boundaries'])) {
                            $boundariesData = $component['snapshot']['memo']['data']['boundaries'];
                            if (!empty($boundariesData) && $boundariesData !== '[]') {

                                break;
                            }
                        }

                        if ((empty($boundariesData) || $boundariesData === '[]') && isset($component['snapshot']['data']['boundaries'])) {
                            $boundariesData = $component['snapshot']['data']['boundaries'];
                            if (!empty($boundariesData) && $boundariesData !== '[]') {

                                break;
                            }
                        }

                        if ((empty($boundariesData) || $boundariesData === '[]') && isset($component['updates'])) {
                            foreach ($component['updates'] as $update) {
                                if (is_array($update) && isset($update['payload']['name'])) {
                                    if (str_contains($update['payload']['name'], 'boundaries')) {
                                        $boundariesData = $update['payload']['value'] ?? null;
                                        if (!empty($boundariesData) && $boundariesData !== '[]') {

                                            break 2;
                                        }
                                    }
                                }
                            }
                        }

                        if ((empty($boundariesData) || $boundariesData === '[]') && isset($component['calls'])) {
                            foreach ($component['calls'] as $call) {
                                if (isset($call['method']) && $call['method'] === 'syncInput') {
                                    $params = $call['params'] ?? [];
                                    if (count($params) >= 2 && str_contains($params[0] ?? '', 'boundaries')) {
                                        $boundariesData = $params[1] ?? null;
                                        if (!empty($boundariesData) && $boundariesData !== '[]') {

                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if (empty($boundariesData) || $boundariesData === '[]') {
                $boundariesData = request()->input('data.boundaries');
                if (!empty($boundariesData) && $boundariesData !== '[]') {
                }
            }

            if (empty($boundariesData) || $boundariesData === '[]') {
                $serverMemo = request()->input('serverMemo.data.boundaries');
                if (!empty($serverMemo) && $serverMemo !== '[]') {
                    $boundariesData = $serverMemo;
                }
            }

            if ($boundariesData && $boundariesData !== '[]' && $boundariesData !== 'null') {

                if (is_string($boundariesData)) {
                    $polygon = $this->jsonToPolygon($boundariesData);
                    if ($polygon) {
                        $data['boundaries'] = $polygon;
                    }
                } else {
                    $data['boundaries'] = $boundariesData;
                }
            }
        }

        if (isset($data['boundaries']) && is_string($data['boundaries']) && $data['boundaries'] !== '' && $data['boundaries'] !== '[]') {
            $polygon = $this->jsonToPolygon($data['boundaries']);
            if ($polygon) {
                $data['boundaries'] = $polygon;
            }
        }

        // Remove surge slot fields from data array - they're not database columns
        // and will be handled separately in afterSave()
        foreach ($this->dayFields as $fieldName) {
            unset($data[$fieldName]);
        }

        return $data;
    }

    protected function beforeSave(): void
    {

        $data = $this->form->getState();

        if (isset($data['status']) && $data['status'] === true) {
            $record = $this->getRecord();
            $city = \App\Models\City::find($record->city_id);

            if ($city && !$city->status) {
                throw ValidationException::withMessages([
                    'status' => 'City is inactive. Cannot activate zone in an inactive city.'
                ]);
            }
        }
    }

    protected function afterSave(): void
    {
        $this->saveSurgeSlots();


        if (!empty($this->mapBoundaries) && $this->mapBoundaries !== '[]' && $this->mapBoundaries !== 'null') {
            try {
                $points = json_decode($this->mapBoundaries, true);
                if ($points && is_array($points) && count($points) >= 3) {

                    $wktPoints = [];
                    foreach ($points as $point) {
                        $wktPoints[] = $point['lng'] . ' ' . $point['lat'];
                    }

                    $wktPoints[] = $points[0]['lng'] . ' ' . $points[0]['lat'];

                    $wkt = 'POLYGON((' . implode(', ', $wktPoints) . '))';




                    $affectedRows = \Illuminate\Support\Facades\DB::update(
                        "UPDATE zones SET boundaries = ST_GeomFromText(?), updated_at = NOW() WHERE id = ?",
                        [$wkt, $this->record->id]
                    );


                    $verification = \Illuminate\Support\Facades\DB::selectOne(
                        "SELECT ST_AsText(boundaries) as boundaries_text FROM zones WHERE id = ?",
                        [$this->record->id]
                    );
                }
            } catch (\Exception $e) {
            }
        }
    }


    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->action(function ($record) {
                    // Block deletion for restricted users (ID 2)
                    $userId = auth()->id();
                    if ($userId === 2) {
                        \Filament\Notifications\Notification::make()
                            ->title('Access Restricted')
                            ->body('In demo mode you are not deleting data...')
                            ->danger()
                            ->send();
                        return;
                    }

                    // Proceed with normal deletion
                    $record->delete();

                    \Filament\Notifications\Notification::make()
                        ->title('Deleted')
                        ->body('The zone has been deleted.')
                        ->success()
                        ->send();

                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }

    protected function saveSurgeSlots(): void
    {
        $data = $this->form->getState();
        $zoneId = $this->record->id;

        foreach ($this->dayFields as $dayOfWeek => $fieldName) {
            $slots = $data[$fieldName] ?? [];
            $existingIds = [];

            foreach ($slots as $slot) {
                if (isset($slot['id']) && $slot['id']) {

                    ZoneSurgeSlot::where('id', $slot['id'])->update([
                        'start_time' => $slot['start_time'],
                        'end_time' => $slot['end_time'],
                        'surge_multiplier' => $slot['surge_multiplier'],
                        'is_active' => $slot['is_active'] ?? true,
                    ]);
                    $existingIds[] = $slot['id'];
                } else {

                    $newSlot = ZoneSurgeSlot::create([
                        'zone_id' => $zoneId,
                        'day_of_week' => $dayOfWeek,
                        'start_time' => $slot['start_time'],
                        'end_time' => $slot['end_time'],
                        'surge_multiplier' => $slot['surge_multiplier'],
                        'is_active' => $slot['is_active'] ?? true,
                    ]);
                    $existingIds[] = $newSlot->id;
                }
            }

            ZoneSurgeSlot::where('zone_id', $zoneId)
                ->where('day_of_week', $dayOfWeek)
                ->whereNotIn('id', $existingIds)
                ->delete();
        }
    }
}
