<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\User;
use App\Models\Document;

class DocumentList extends Model
{
    protected $fillable = [
        'name',
        'type',
        'description',
        'is_required',
        'is_active',
        'is_new',
        'upload_deadline_hours',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'is_new' => 'boolean',
        'upload_deadline_hours' => 'integer',
    ];

    
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($documentList) {
            if (!$documentList->is_new) {
                $documentList->upload_deadline_hours = null;
            }
        });

        static::created(function ($documentList) {
            if ($documentList->is_active && $documentList->is_new) {
                $documentList->notifyAllDrivers();
            }
        });

        static::updating(function ($documentList) {
            if ($documentList->isDirty('name')) {
                $oldName = $documentList->getOriginal('name');
                $newName = $documentList->name;

                if ($oldName && $newName && $oldName !== $newName) {
                    Document::where('type', $oldName)
                        ->update(['type' => $newName]);

                    $normalizedOldName = strtolower(str_replace(' ', '_', $oldName));

                    if ($normalizedOldName !== $oldName) {
                        Document::where('type', $normalizedOldName)
                            ->update(['type' => $newName]); // Use new name as-is from document_lists
                    }
                }
            }
        });
    }

    
    public function notifyAllDrivers(): void
    {
        

        $deadlineHours = $this->upload_deadline_hours
            ?? \App\Models\SystemConfiguration::getValue('document_upload_deadline_hours', 24);

        

        $drivers = User::drivers()
            ->active()
            ->whereNotNull('device_token')
            ->with(['documents', 'vehicles.documents'])
            ->get();


        $deadlineAt = now()->addHours($deadlineHours);

        $notifiedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $skippedReasons = [
            'already_has_document' => 0,
            'no_device_token' => 0,
            'notification_failed' => 0,
        ];

        foreach ($drivers as $driver) {
            try {
                $hasDocument = $this->checkDriverHasDocument($driver);

                if (!$hasDocument) {
                    try {
                        $notification = \App\Models\DriverDocumentNotification::updateOrCreate(
                            [
                                'driver_id' => $driver->id,
                                'document_list_id' => $this->id,
                            ],
                            [
                                'notified_at' => now(),
                                'deadline_at' => $deadlineAt,
                                'is_uploaded' => false,
                            ]
                        );


                        $pushService = app(\App\Services\PushNotificationService::class);
                        $notificationSent = $pushService->sendNewDocumentNotification($driver, $this, $deadlineHours);

                        if ($notificationSent) {
                            $notifiedCount++;
                            
                        } else {
                            $errorCount++;
                            $skippedReasons['notification_failed']++;
                            
                        }
                    } catch (\Exception $e) {
                        $errorCount++;
                        $skippedReasons['notification_failed']++;
                    }
                } else {
                    $skippedCount++;
                    $skippedReasons['already_has_document']++;
                    
                }
            } catch (\Exception $e) {
                $errorCount++;
            }
        }

    }

    
    protected function checkDriverHasDocument(User $driver): bool
    {
        $fieldName = $this->getDocumentFieldName($this->name);

        if ($this->type === 'driver') {
            $hasFront = $driver->documents()->where('type', $fieldName . '_front')->exists();
            $hasBack = $driver->documents()->where('type', $fieldName . '_back')->exists();
            $hasSingle = $driver->documents()->where('type', $fieldName)->exists();

            return $hasFront || $hasBack || $hasSingle;
        } else {
            foreach ($driver->vehicles as $vehicle) {
                if ($vehicle->documents()->where('type', $fieldName)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    
    protected function getDocumentFieldName(string $documentName): string
    {
        $fieldName = strtolower(str_replace(' ', '_', $documentName));
        $fieldName = str_replace(['_certificate', '_cert'], '', $fieldName);
        return $fieldName;
    }

    public function scopeDriver($query)
    {
        return $query->where('type', 'driver');
    }

    public function scopeVehicle($query)
    {
        return $query->where('type', 'vehicle');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    
    public static function getRequiredTypeMap(): array
    {
        $documents = self::query()
            ->where('is_required', true)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('is_new', false)
                    ->orWhereNull('is_new');
            })
            ->get();

        $required = [
            'driver' => [],
            'vehicle' => [],
        ];

        foreach ($documents as $document) {
            $normalized = Document::normalizeDocumentType($document->name);

            if (!$normalized) {
                continue;
            }

            if ($document->type === 'driver') {
                $required['driver'][] = $normalized;

                if (!str_ends_with($normalized, '_front') && !str_ends_with($normalized, '_back')) {
                    $required['driver'][] = "{$normalized}_front";
                    $required['driver'][] = "{$normalized}_back";
                }
            } elseif ($document->type === 'vehicle') {
                $required['vehicle'][] = $normalized;
            }
        }

        return [
            'driver' => array_values(array_unique(array_filter($required['driver']))),
            'vehicle' => array_values(array_unique(array_filter($required['vehicle']))),
        ];
    }
}
