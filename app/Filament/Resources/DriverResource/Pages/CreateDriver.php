<?php

namespace App\Filament\Resources\DriverResource\Pages;

use Exception;
use Filament\Notifications\Notification;
use App\Filament\Resources\DriverResource;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Document;
use App\Models\DocumentList;
use App\Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateDriver extends CreateRecord
{
    protected static string $resource = DriverResource::class;

    protected static ?string $title = 'Create Driver';

    public function getTitle(): string
    {
        return 'Create Driver';
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            DB::beginTransaction();

            if (Vehicle::where('registration_number', $data['registration_number'])->exists()) {
                throw new Exception('This vehicle registration number is already registered. Please use a different registration number.');
            }

            $userData = [
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'role_id' => 2, // Driver role
                'status' => $data['is_active'] ? 'active' : 'inactive',
                'profile_photo' => $data['profile_photo'] ?? null,
                'is_online' => $data['is_available'] ?? false,
                'date_of_birth' => $data['date_of_birth'] ?? null,
            ];

            $user = User::create($userData);

            if (!$user->referral_code) {
                $user->generateReferralCode();
            }

            $driverProfileData = [
                'driver_id' => $user->id,
                'identity_verified_at' => $data['is_verified'] ? now() : null,
                'bank_verified_at' => $data['is_verified'] ? now() : null,
                'address_verified_at' => $data['is_verified'] ? now() : null,
            ];

            $user->driverProfile()->create($driverProfileData);

            $vehicleData = [
                'driver_id' => $user->id,
                'ride_type_id' => $data['ride_type_id'],
                'brand' => $data['brand'],
                'model' => $data['model'],
                'year' => $data['year'],
                'registration_number' => $data['registration_number'],

                'license_plate' => $data['registration_number'],
                'color' => 'Unknown', // Default color
                'registration_expiry' => now()->addYears(5), // Default expiry
                'insurance_expiry' => now()->addYears(1), // Default insurance expiry
                'status' => 'active',
            ];

            $vehicle = $user->vehicles()->create($vehicleData);

            if (!empty($data['government_id_front'])) {
                Document::create([
                    'documentable_type' => User::class,
                    'documentable_id' => $user->id,
                    'type' => 'government_id_front',
                    'file_front' => $data['government_id_front'],
                    'status' => 'pending',
                ]);
            }

            if (!empty($data['government_id_back'])) {
                Document::create([
                    'documentable_type' => User::class,
                    'documentable_id' => $user->id,
                    'type' => 'government_id_back',
                    'file_front' => $data['government_id_back'],
                    'status' => 'pending',
                ]);
            }

            if (!empty($data['live_selfie'])) {
                Document::create([
                    'documentable_type' => User::class,
                    'documentable_id' => $user->id,
                    'type' => 'live_selfie',
                    'file_front' => $data['live_selfie'],
                    'status' => 'pending',
                ]);
            }

            if (!empty($data['vehicle_documents'])) {
                foreach ($data['vehicle_documents'] as $docData) {
                    if (!empty($docData['document_file'])) {
                        $documentList = DocumentList::find($docData['document_type']);

                        Document::create([
                            'documentable_type' => Vehicle::class,
                            'documentable_id' => $vehicle->id,
                            'type' => $documentList ? strtolower(str_replace(' ', '_', $documentList->name)) : 'vehicle_document',
                            'number' => $docData['document_number'] ?? null,
                            'file_front' => $docData['document_file'],
                            'expiry_date' => $docData['expiry_date'] ?? null,
                            'status' => 'pending',
                        ]);
                    }
                }
            }

            DB::commit();

            return $user;
        } catch (Exception $e) {
            DB::rollBack();

            Notification::make()
                ->title('Error creating driver')
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw $e;
        }
    }
}
