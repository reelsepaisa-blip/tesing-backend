<?php

namespace App\Filament\Resources\DriverResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Exception;
use App\Filament\Resources\DriverResource;
use App\Models\Document;
use App\Models\DocumentList;
use App\Models\Vehicle;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\View;
use App\Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;

class EditDriver extends EditRecord
{
    protected static string $resource = DriverResource::class;

    protected static ?string $title = 'Edit Driver';

    public function getTitle(): string
    {
        return 'Edit Driver';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add_document')
                ->label('Add Document')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->schema([
                    Select::make('document_type')
                        ->label('Document Type')
                        ->options(fn() => $this->getDocumentTypeOptions())
                        ->searchable()
                        ->required(),

                    FileUpload::make('file_front')
                        ->label('Front Side / Document File')
                        ->disk('public')
                        ->directory('documents/driver')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg'])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $driver = $this->record;
                    Document::create([
                        'documentable_type' => get_class($driver),
                        'documentable_id' => $driver->id,
                        'type' => $data['document_type'],
                        'file_front' => $data['file_front'],
                        'status' => 'pending',
                    ]);

                    Notification::make()
                        ->title('Document added successfully')
                        ->success()
                        ->send();
                }),

        ];
    }

    protected function getDocumentTypeOptions(): array
    {
        $options = [
            'government_id_front' => 'Government ID (Front)',
            'government_id_back' => 'Government ID (Back)',
            'live_selfie' => 'Selfie',
        ];

        $documents = DocumentList::active()->ordered()->get();
        foreach ($documents as $doc) {
            $key = Document::normalizeDocumentType($doc->name) ?? $doc->name;
            $options[$key] = ucwords(str_replace('_', ' ', $doc->name));
        }

        return $options;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Load relationships
        $this->record->load(['vehicles.rideType', 'driverProfile', 'documents', 'vehicles.documents']);

        // Get the first vehicle
        $vehicle = $this->record->vehicles->first();

        // Populate vehicle data into form
        if ($vehicle) {
            $data['ride_type_id'] = $vehicle->ride_type_id;
            $data['registration_number'] = $vehicle->registration_number;
            $data['brand'] = $vehicle->brand;
            $data['model'] = $vehicle->model;
            $data['year'] = $vehicle->year;
        }

        // Get vehicle registration status from driverProfile meta_data
        $metaData = $this->record->driverProfile?->meta_data ?? [];
        if (isset($metaData['vehicle_registration_status'])) {
            $data['vehicle_registration_status'] = $metaData['vehicle_registration_status'];
        } elseif ($vehicle && $vehicle->status) {
            $data['vehicle_registration_status'] = $vehicle->status;
        }

        // Get vehicle registration rejection reason
        if (isset($metaData['vehicle_registration_rejection_reason'])) {
            $data['vehicle_registration_rejection_reason'] = $metaData['vehicle_registration_rejection_reason'];
        } elseif ($vehicle && $vehicle->rejection_reason) {
            $data['vehicle_registration_rejection_reason'] = $vehicle->rejection_reason;
        }

        // Populate status fields
        $data['is_active'] = $this->record->is_active ?? ($this->record->status === 'active');
        $data['is_available'] = $this->record->is_available ?? false;

        // Populate driver documents
        $driverDocuments = $this->record->documents()
            ->where('documentable_type', get_class($this->record))
            ->get();
        
        if ($driverDocuments->isNotEmpty()) {
            $data['driver_document_requests'] = $this->mapDocumentsForRequest($driverDocuments);
        }

        // Populate vehicle documents
        if ($vehicle) {
            $vehicleDocuments = $vehicle->documents()
                ->where('documentable_type', Vehicle::class)
                ->get();
            
            if ($vehicleDocuments->isNotEmpty()) {
                $data['vehicle_document_requests'] = $this->mapDocumentsForRequest($vehicleDocuments);
            }
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {

            $record->load(['driverProfile', 'vehicles', 'documents', 'currentAttendanceSession']);

            $vehicle = $record->vehicles->first();
            if (
                $vehicle && Vehicle::where('registration_number', $data['registration_number'])
                ->where('id', '!=', $vehicle->id)
                ->exists()
            ) {
                throw new Exception('This vehicle registration number is already registered. Please use a different registration number.');
            }

            if (($data['is_available'] ?? false) && !$record->is_online) {

                $hasRecentActivity = $record->last_location_at &&
                    $record->last_location_at->gt(now()->subMinutes(10));
                $hasActiveSession = $record->currentAttendanceSession !== null;

                if (!$hasRecentActivity && !$hasActiveSession) {
                    throw new Exception('Cannot set "Available for Rides" when the driver is offline. The driver must be actively connected to the app first.');
                }
            }

            $userData = [
                'name' => $data['name'],

                'phone' => $data['phone'],
                'profile_photo' => $data['profile_photo'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'status' => ($data['is_active'] ?? $record->is_active) ? 'active' : 'inactive',
                'is_online' => $data['is_available'] ?? false,
            ];

            if (isset($data['email']) && !empty(trim($data['email']))) {
                $userData['email'] = trim($data['email']);
            }

            $record->update($userData);

            $vehicleRegistrationStatus = $data['vehicle_registration_status'] ?? 'pending';
            $vehicleRegistrationReason = $vehicleRegistrationStatus === 'rejected'
                ? ($data['vehicle_registration_rejection_reason'] ?? null)
                : null;

            $metaData = $record->driverProfile?->meta_data ?? [];
            $metaData['vehicle_registration_status'] = $vehicleRegistrationStatus;
            $metaData['vehicle_registration_approved'] = $vehicleRegistrationStatus === 'approved';
            $metaData['vehicle_registration_rejection_reason'] = $vehicleRegistrationReason;

            $isVerified = $data['is_verified'] ?? false;

            if ($record->driverProfile) {
                $driverProfileData = [
                    'identity_verified_at' => $isVerified ? now() : null,
                    'bank_verified_at' => $isVerified ? now() : null,
                    'address_verified_at' => $isVerified ? now() : null,
                    'meta_data' => $metaData,
                ];

                $record->driverProfile->update($driverProfileData);
            } else {
                $record->driverProfile()->create([
                    'identity_verified_at' => $isVerified ? now() : null,
                    'bank_verified_at' => $isVerified ? now() : null,
                    'address_verified_at' => $isVerified ? now() : null,
                    'meta_data' => $metaData,
                ]);
            }

            if ($vehicle) {
                $vehicleData = [
                    'ride_type_id' => $data['ride_type_id'],
                    'brand' => $data['brand'],
                    'model' => $data['model'],
                    'year' => $data['year'],
                    'registration_number' => $data['registration_number'],

                    'license_plate' => $data['registration_number'],
                ];
                $vehicle->update($vehicleData);
            }

            if (!empty($data['government_id_front'])) {
                $record->documents()->updateOrCreate(
                    ['type' => 'government_id_front'],
                    [
                        'file_front' => $data['government_id_front'],
                        'status' => 'pending',
                    ]
                );
            }

            if (!empty($data['government_id_back'])) {
                $record->documents()->updateOrCreate(
                    ['type' => 'government_id_back'],
                    [
                        'file_front' => $data['government_id_back'],
                        'status' => 'pending',
                    ]
                );
            }

            if (!empty($data['live_selfie'])) {
                $record->documents()->updateOrCreate(
                    ['type' => 'live_selfie'],
                    [
                        'file_front' => $data['live_selfie'],
                        'status' => 'pending',
                    ]
                );
            }

            if (!empty($data['vehicle_documents']) && $vehicle) {

                $vehicle->documents()->delete();

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

            $this->processDocumentStatusUpdates($data['driver_document_requests'] ?? []);
            $this->processDocumentStatusUpdates($data['vehicle_document_requests'] ?? []);

            return $record;
        } catch (Exception $e) {

            Notification::make()
                ->title('Error updating driver')
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw $e;
        }
    }

    protected function mapDocumentsForRequest($documents): array
    {
        return $documents->map(function (Document $document) {
            $displayName = ucwords(str_replace('_', ' ', $document->type));

            return [
                'id' => $document->id,
                'display_name' => $displayName,
                'front_url' => $document->file_front ? url('storage/' . ltrim($document->file_front, '/')) : null,
                'back_url' => $document->file_back ? url('storage/' . ltrim($document->file_back, '/')) : null,
                'submitted_at' => optional($document->created_at)?->toDayDateTimeString(),
                'status' => $document->status ?? 'pending',
                'rejection_reason' => $document->rejection_reason,
            ];
        })->toArray();
    }

    protected function processDocumentStatusUpdates(array $documents): void
    {
        collect($documents)
            ->filter(fn($doc) => !empty($doc['id']) && !empty($doc['status']))
            ->each(function ($doc) {
                $document = Document::find($doc['id']);

                if (!$document) {
                    return;
                }

                $status = $doc['status'];
                if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
                    return;
                }

                $rejectionReason = $status === 'rejected'
                    ? ($doc['rejection_reason'] ?? null)
                    : null;

                $document->update([
                    'status' => $status,
                    'rejection_reason' => $rejectionReason,
                    'verified_by' => Auth::id(),
                    'verified_at' => in_array($status, ['approved', 'rejected'], true) ? now() : null,
                ]);
            });
    }
}
