<?php

namespace App\Filament\Resources\CityResource\Pages;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TimePicker;
use Illuminate\Database\Eloquent\Model;
use Exception;
use App\Filament\Resources\CityResource;
use App\Forms\Components\CityAutocomplete;
use App\Models\City;
use App\Models\Zone;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Document;
use App\Models\DocumentList;
use App\Models\DriverProfile;
use App\Models\DriverLocation;
use Filament\Actions;
use App\Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\QueryException;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

use Illuminate\Support\Facades\Hash;
use MatanYadaev\EloquentSpatial\Objects\Polygon;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Objects\LineString;
use Filament\Forms;

class CreateCity extends CreateRecord
{
    protected static string $resource = CityResource::class;

    public function form(Schema $schema): Schema
    {
        // Build components
        $components = [
            Tabs::make('City Management')
                ->tabs([
                    Tab::make('Basic Information')
                        ->schema([
                            CityAutocomplete::make('name')
                                ->label('City Name')
                                ->placeholder('Enter a Location')
                                ->stateField('state')
                                ->countryField('country')
                                ->latitudeField('latitude')
                                ->longitudeField('longitude')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan('full')
                                ->rules([
                                    function () {
                                        return function (string $attribute, $value, Closure $fail) {
                                            $state = request()->input('state');
                                            $country = request()->input('country');

                                            if ($state && $country) {
                                                $exists = City::where('name', $value)
                                                    ->where('state', $state)
                                                    ->where('country', $country)
                                                    ->exists();

                                                if ($exists) {
                                                    $fail("The city '{$value}, {$state}, {$country}' already exists in the system.");
                                                }
                                            }
                                        };
                                    },
                                ]),
                            TextInput::make('state')
                                ->label('State/Province')
                                ->placeholder('State will be auto-filled')
                                ->maxLength(255)
                                ->columnSpan('full'),
                            TextInput::make('country')
                                ->label('Country')
                                ->placeholder('Country will be auto-filled')
                                ->default('India')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan('full'),
                            Grid::make(2)
                                ->schema([
                                    TextInput::make('latitude')
                                        ->label('Latitude')
                                        ->placeholder('Will be auto-filled')
                                        ->required()
                                        ->numeric()
                                        ->minValue(-90)
                                        ->maxValue(90)
                                        ->step(0.000001),
                                    TextInput::make('longitude')
                                        ->label('Longitude')
                                        ->placeholder('Will be auto-filled')
                                        ->required()
                                        ->numeric()
                                        ->minValue(-180)
                                        ->maxValue(180)
                                        ->step(0.000001),
                                ])
                                ->columnSpan('full'),
                            Toggle::make('status')
                                ->label('Active')
                                ->default(true)
                                ->required()
                                ->columnSpan('full'),
                        ]),
                    Tab::make('Service Hours')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    TimePicker::make('service_start_time')
                                        ->default('06:00:00')
                                        ->required(),
                                    TimePicker::make('service_end_time')
                                        ->default('23:00:00')
                                        ->required(),
                                ])
                                ->columnSpan('full'),
                        ]),
                    Tab::make('Night Charges')
                        ->schema([
                            Grid::make(3)
                                ->schema([
                                    TextInput::make('night_charge_multiplier')
                                        ->label('Night Charge Multiplier')
                                        ->default(1.50)
                                        ->numeric()
                                        ->required()
                                        ->minValue(1)
                                        ->maxValue(5)
                                        ->step(0.01)
                                        ->suffix('x'),
                                    TimePicker::make('night_start_time')
                                        ->default('22:00:00')
                                        ->required(),
                                    TimePicker::make('night_end_time')
                                        ->default('06:00:00')
                                        ->required(),
                                ])
                                ->columnSpan('full'),
                        ]),
                ])
                ->columnSpan('full'),
        ];

        return $schema
            ->components($components)
            ->columns(1);
    }

    protected function handleRecordCreation(array $data): Model
    {

        $existingCity = City::where('name', $data['name'])
            ->where('state', $data['state'])
            ->where('country', $data['country'])
            ->first();

        if ($existingCity) {
            Notification::make()
                ->title('City Already Exists')
                ->body("The city '{$data['name']}, {$data['state']}, {$data['country']}' already exists in the system.")
                ->danger()
                ->send();

            $this->halt();
        }

        try {
            DB::beginTransaction();

            // Create the city
            $city = static::getModel()::create($data);

            DB::commit();

            Notification::make()
                ->title('City Created Successfully')
                ->body("City '{$city->name}' has been created successfully.")
                ->success()
                ->send();

            return $city;
        } catch (QueryException $e) {

            if ($e->errorInfo[1] == 1062) { // Duplicate entry error code
                Notification::make()
                    ->title('City Already Created')
                    ->body("A city with this name, state, and country combination already exists.")
                    ->danger()
                    ->send();

                $this->halt();
            }

            throw $e;
        } catch (Exception $e) {
            DB::rollBack();

            Notification::make()
                ->title('Error Creating City')
                ->body("Failed to create city: " . $e->getMessage())
                ->warning()
                ->send();

            throw $e;
        }
    }

    protected function createDefaultZone(City $city): void
    {
        try {
            // Create a default zone covering the city area
            // Using a 50km radius (approximately 0.45 degrees)
            $latOffset = 0.45;
            $lngOffset = 0.45;

            $centerLat = $city->latitude ?? 0;
            $centerLng = $city->longitude ?? 0;

            $minLat = $centerLat - $latOffset;
            $maxLat = $centerLat + $latOffset;
            $minLng = $centerLng - $lngOffset;
            $maxLng = $centerLng + $lngOffset;

            // Create Polygon points
            $points = [
                new Point($minLat, $minLng), // Bottom-left
                new Point($minLat, $maxLng), // Bottom-right
                new Point($maxLat, $maxLng), // Top-right
                new Point($maxLat, $minLng), // Top-left
                new Point($minLat, $minLng), // Close the polygon
            ];

            $lineString = new LineString($points);
            $polygon = new Polygon([$lineString]);

            // Extract only the first part of city name (before comma) for zone name
            $cityNameParts = explode(',', $city->name);
            $cityName = trim($cityNameParts[0]);

            $zone = Zone::create([
                'city_id' => $city->id,
                'name' => $cityName . ' - Default Zone',
                'description' => 'Auto-generated default zone for ' . $city->name,
                'boundaries' => $polygon,
                'status' => true,
                'surge_multiplier' => 1.0,
                'meta_data' => [
                    'supported_ride_types' => [1, 2, 3],
                    'pickup_allowed' => true,
                    'drop_allowed' => true,
                    'driver_assignment_radius' => 5000,
                ],
            ]);


        } catch (Exception $e) {
            throw $e;
        }
    }

    protected function createDriverForCity(City $city): void
    {
        try {
            // Extract only the first part of city name (before comma) for email
            $cityNameParts = explode(',', $city->name);
            $cityName = strtolower(str_replace(' ', '', trim($cityNameParts[0])));
            $email = "{$cityName}driver@etaxi.com";
            $phone = '999999' . str_pad($city->id, 4, '0', STR_PAD_LEFT);

            // Check if driver already exists
            $existingDriver = User::where(function ($query) use ($email, $phone) {
                $query->where('email', $email)
                    ->orWhere('phone', $phone);
            })
                ->where('role_id', 2)
                ->first();

            if ($existingDriver) {

                return;
            }

            // Step 0: Create user with location
            $user = $this->registerStep0($city);

            // Step 1: Personal Information
            $user = $this->registerStep1($user, $city, $email);

            // Step 2: Vehicle Information
            $user = $this->registerStep2($user);

            // Step 3: Documents
            $this->registerStep3($user, $user->vehicles()->first());

            // Set driver status to active and is_online to 1
            $latitude = $this->roundCoordinate((float) $city->latitude, 8);
            $longitude = $this->roundCoordinate((float) $city->longitude, 8);

            $lastLongitude = abs((float) $city->longitude) > 99.99999999 ? null : $longitude;

            $updateData = [
                'status' => 'active',
                'is_online' => 1,
                'last_latitude' => $latitude,
                'select_latitude' => $latitude,
                'select_longitude' => $longitude,
                'last_location_at' => now(),
            ];

            if ($lastLongitude !== null) {
                $updateData['last_longitude'] = $lastLongitude;
            }

            $user->update($updateData);

            // Get the default zone for this city
            $zone = Zone::where('city_id', $city->id)->first();

            // Create or update driver location in driver_locations table with city center coordinates
            DriverLocation::updateOrCreate(
                ['driver_id' => $user->id],
                [
                    'zone_id' => $zone ? $zone->id : null,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'is_active' => true,
                    'recorded_at' => now(),
                ]
            );


        } catch (Exception $e) {
            throw $e;
        }
    }

    protected function registerStep0(City $city): User
    {
        $userData = [
            'role_id' => 2,  // Driver role
            'status' => 'incomplete',
            'device_token' => '',
            'password' => Hash::make('Demodriver@123'),  // Set default password for city drivers
        ];

        $user = User::create($userData);

        // Generate bearer token
        $bearerToken = 'Bearer_' . dechex($user->id) . '_' . bin2hex(random_bytes(32));
        $user->update([
            'bearer_token' => $bearerToken,
            'token_expires_at' => null,
        ]);

        // Update location and city
        $latitude = $this->roundCoordinate((float) $city->latitude, 8);
        $longitude = $this->roundCoordinate((float) $city->longitude, 8);

        $updateData = [
            'select_latitude' => $latitude,
            'select_longitude' => $longitude,
            'city_id' => $city->id,
            'step_0' => 1,
        ];

        if (abs((float) $city->longitude) <= 99.99999999) {
            $updateData['last_latitude'] = $latitude;
            $updateData['last_longitude'] = $longitude;
        }

        $user->update($updateData);

        return $user;
    }

    protected function registerStep1(User $user, City $city, string $email): User
    {
        // Extract only the first part of city name (before comma)
        $cityNameParts = explode(',', $city->name);
        $cityName = strtolower(str_replace(' ', '', trim($cityNameParts[0])));
        $phone = '999999' . str_pad($city->id, 4, '0', STR_PAD_LEFT);

        // Check if phone already exists, if so, append user ID to make it unique
        $existingUserWithPhone = User::where('phone', $phone)
            ->where('role_id', 2)
            ->where('id', '!=', $user->id)
            ->first();

        if ($existingUserWithPhone) {
            $phone = '999999' . str_pad($city->id, 4, '0', STR_PAD_LEFT) . $user->id;
        }

        $userData = [
            'name' => ucfirst($city->name) . ' Driver',
            'email' => $email,
            'phone' => $phone,
            'date_of_birth' => '1990-01-01',
            'status' => 'under_review',
            'phone_verified_at' => now(),
            'step_1' => 1,
        ];

        $user->update($userData);

        // Generate referral code if not exists
        if (empty($user->referral_code)) {
            $user->generateReferralCode();
        }

        return $user->fresh();
    }

    protected function registerStep2(User $user): User
    {
        // Get first ride type ID
        $rideTypeId = DB::table('ride_types')->value('id') ?? 1;
        $registrationNumber = 'CITY-' . $user->city_id . '-' . $user->id;
        $licensePlate = 'CITY-' . $user->city_id . '-' . $user->id;

        $vehicleData = [
            'driver_id' => $user->id,
            'ride_type_id' => $rideTypeId,
            'brand' => 'Toyota',
            'model' => 'Camry',
            'year' => date('Y'),
            'registration_number' => $registrationNumber,
            'license_plate' => $licensePlate,
            'color' => 'White',
            'registration_expiry' => now()->addYears(5),
            'insurance_expiry' => now()->addYears(1),
            'status' => 'active',
            'step_2' => 1,
        ];

        if ($user->vehicles()->exists()) {
            $vehicle = $user->vehicles()->first();
            $vehicle->update($vehicleData);
        } else {
            $vehicle = $user->vehicles()->create($vehicleData);
        }

        $user->update(['step_2' => 1]);

        return $user;
    }

    protected function registerStep3(User $user, Vehicle $vehicle): void
    {
        // Create or update driver profile
        $driverProfile = DriverProfile::firstOrCreate(
            ['driver_id' => $user->id],
            [
                'city_id' => $user->city_id,
                'meta_data' => []
            ]
        );

        if (!$driverProfile->city_id && $user->city_id) {
            $driverProfile->update(['city_id' => $user->city_id]);
        }

        // Update vehicle details
        $vehicle->update([
            'brand' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020,
            'step_3' => 1,
        ]);

        // Create dummy image files for documents
        $insuranceDPath = $this->createDummyImageFile('insurance_d');

        // Store documents (insurance_d is a driver document)
        $this->createDocument($user, 'insurance_d', $insuranceDPath);

        // Create vehicle insurance document
        $vehicleInsurancePath = $this->createDummyImageFile('insurance');
        $storagePath = 'documents/vehicle/' . basename($vehicleInsurancePath);
        Storage::disk('public')->put($storagePath, File::get($vehicleInsurancePath));

        Document::updateOrCreate(
            [
                'documentable_type' => Vehicle::class,
                'documentable_id' => $vehicle->id,
                'type' => 'insurance',
            ],
            [
                'file_front' => $storagePath,
                'status' => 'approved',
            ]
        );

        @unlink($vehicleInsurancePath);

        // Create other required documents if needed
        $documentLists = DocumentList::where('is_active', true)
            ->where('is_required', true)
            ->where('type', 'driver')
            ->get();

        foreach ($documentLists as $docList) {
            $fieldName = $this->getDocumentFieldName($docList->name);

            // Skip insurance_d as we already created it
            if ($fieldName === 'insurance_d') {
                continue;
            }

            // Skip if already exists
            if ($user->documents()->where('type', $fieldName)->exists()) {
                continue;
            }

            // Create dummy document
            $docPath = $this->createDummyImageFile($fieldName);
            $this->createDocument($user, $fieldName, $docPath);
            @unlink($docPath);
        }

        // Clean up temp files
        @unlink($insuranceDPath);

        // Mark step 3 as completed
        $user->update([
            'step_3' => 1,
            'is_register' => 1,
        ]);

        // Auto-approve all documents (verify them)
        $user->documents()->update(['status' => 'approved']);
        $vehicle->documents()->update(['status' => 'approved']);

        // Update verification status
        $user->updateVerificationStatus();

        // Ensure is_verified is set to true
        $user->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }

    protected function getDocumentFieldName(string $name): string
    {
        return strtolower(str_replace([' ', '-'], '_', $name));
    }

    protected function createDocument(User $user, string $type, string $filePath): void
    {
        $storagePath = 'documents/driver/' . basename($filePath);
        Storage::disk('public')->put($storagePath, File::get($filePath));

        Document::updateOrCreate(
            [
                'documentable_type' => User::class,
                'documentable_id' => $user->id,
                'type' => $type,
            ],
            [
                'file_front' => $storagePath,
                'status' => 'approved',
            ]
        );
    }

    protected function createDummyImageFile(string $name): string
    {
        // Create a 100x100 pixel PNG image
        $image = imagecreatetruecolor(100, 100);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);

        $tempPath = sys_get_temp_dir() . '/' . $name . '_' . uniqid() . '.png';
        imagepng($image, $tempPath);
        imagedestroy($image);

        return $tempPath;
    }

    protected function roundCoordinate(float $coordinate, int $decimals = 8): float
    {
        return round($coordinate, $decimals);
    }
}
