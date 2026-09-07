<?php

namespace App\Console\Commands;

use Exception;
use App\Models\City;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Document;
use App\Models\DocumentList;
use App\Models\DriverProfile;
use App\Models\DriverLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class CreateCityWiseDrivers extends Command
{
    protected $signature = 'drivers:create-citywise {--skip-existing : Skip cities that already have drivers}';

    protected $description = 'Create drivers for all cities with city-specific emails and complete registration steps 0-3';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $cities = City::all();
        $skipExisting = $this->option('skip-existing');

        $this->info("Found {$cities->count()} cities. Starting driver creation...");
        $this->newLine();

        $successCount = 0;
        $skipCount = 0;
        $errorCount = 0;

        foreach ($cities as $city) {
            // Extract only the first part of city name (before comma) for email
            $cityNameParts = explode(',', $city->name);
            $cityName = strtolower(str_replace(' ', '', trim($cityNameParts[0])));
            $email = "{$cityName}driver@etaxi.com";
            $phone = '999999' . str_pad($city->id, 4, '0', STR_PAD_LEFT);

            // Check if driver already exists for this city (by email or phone)
            $existingDriver = User::where(function($query) use ($email, $phone) {
                $query->where('email', $email)
                      ->orWhere('phone', $phone);
            })
            ->where('role_id', 2)
            ->first();

            if ($existingDriver && $skipExisting) {
                $this->warn("Skipping {$city->name} - driver already exists (Email: {$email} or Phone: {$phone})");
                $skipCount++;
                continue;
            }

            if ($existingDriver) {
                $this->warn("Driver already exists for {$city->name} (Email: {$email} or Phone: {$phone}). Deleting existing driver...");
                // Delete related records first
                $existingDriver->vehicles()->delete();
                $existingDriver->documents()->delete();
                $existingDriver->delete();
            }

            try {
                DB::beginTransaction();

                $this->info("Creating driver for {$city->name}...");

                // Step 0: Location
                $user = $this->registerStep0($city);

                // Step 1: Personal Information
                $user = $this->registerStep1($user, $city, $email);

                // Step 2: Vehicle Information
                $vehicle = $this->registerStep2($user);

                // Step 3: Documents
                $this->registerStep3($user, $vehicle);

                // Set driver status to active and is_online to 1
                // Note: last_longitude has decimal(10,8) precision (max 2 digits before decimal)
                // So we need to round longitudes that exceed this range
                $latitude = $this->roundCoordinate($city->latitude, 8);
                $longitude = $this->roundCoordinate($city->longitude, 8);
                
                // For last_longitude with decimal(10,8), max value is 99.99999999
                // So we need to ensure longitude fits within -99.99999999 to 99.99999999
                // If it doesn't fit, we'll use select_longitude which has decimal(11,8)
                $lastLongitude = abs($city->longitude) > 99.99999999 ? null : $longitude;
                
                $updateData = [
                    'status' => 'active',
                    'is_online' => 1,
                    'last_latitude' => $latitude,
                    'select_latitude' => $latitude,
                    'select_longitude' => $longitude, // This column has decimal(11,8) so it can handle larger values
                    'last_location_at' => now(),
                ];
                
                // Only set last_longitude if it fits within the precision
                if ($lastLongitude !== null) {
                    $updateData['last_longitude'] = $lastLongitude;
                }
                
                $user->update($updateData);

                // Create or update driver location in driver_locations table with city center coordinates
                DriverLocation::updateOrCreate(
                    ['driver_id' => $user->id],
                    [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'is_active' => true,
                        'recorded_at' => now(),
                    ]
                );

                DB::commit();

                $this->info("✓ Successfully created driver for {$city->name} (ID: {$user->id}, Email: {$email})");
                $successCount++;

            } catch (Exception $e) {
                DB::rollBack();
                $this->error("✗ Failed to create driver for {$city->name}: " . $e->getMessage());
                $errorCount++;
            }

            $this->newLine();
        }

        $this->newLine();
        $this->info("=== Summary ===");
        $this->info("Successfully created: {$successCount}");
        $this->info("Skipped: {$skipCount}");
        $this->info("Errors: {$errorCount}");
        $this->info("Total processed: " . ($successCount + $skipCount + $errorCount));

        return 0;
    }

    protected function registerStep0(City $city): User
    {
        // Create a new incomplete user for step 0
        $userData = [
            'role_id' => 2,  // Driver role
            'status' => 'incomplete',
            'device_token' => '',
        ];

        $user = User::create($userData);

        // Generate bearer token
        $bearerToken = 'Bearer_' . dechex($user->id) . '_' . bin2hex(random_bytes(32));
        $user->update([
            'bearer_token' => $bearerToken,
            'token_expires_at' => null,
        ]);

        // Update location and city
        // Note: last_longitude has decimal(10,8) precision (max 2 digits before decimal)
        // So we only set it if longitude fits within -99.99999999 to 99.99999999
        $latitude = $this->roundCoordinate($city->latitude, 8);
        $longitude = $this->roundCoordinate($city->longitude, 8);
        
        $updateData = [
            'select_latitude' => $latitude,
            'select_longitude' => $longitude, // This column has decimal(11,8) so it can handle larger values
            'city_id' => $city->id,
            'step_0' => 1,
        ];
        
        // Only set last_longitude if it fits within the precision (decimal(10,8) = max 99.99999999)
        if (abs($city->longitude) <= 99.99999999) {
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
        $phone = '999999' . str_pad($city->id, 4, '0', STR_PAD_LEFT); // Generate unique phone
        
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

    protected function registerStep2(User $user): Vehicle
    {
        // Get first ride type ID
        $rideTypeId = DB::table('ride_types')->value('id') ?? 1;
        $registrationNumber = 'CITY-' . $user->city_id . '-' . $user->id;
        $licensePlate = 'CITY-' . $user->city_id . '-' . $user->id; // Make license plate unique with user ID

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

        return $vehicle;
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
        
        // Update city_id if not set
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
        $insurancePath = $this->createDummyImageFile('insurance');

        // Store documents (insurance_d is a driver document, insurance is vehicle document)
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
            @unlink($docPath); // Clean up temp file
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
        // Create a 1x1 pixel PNG image
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
