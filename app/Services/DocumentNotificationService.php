<?php

namespace App\Services;

use App\Models\User;
use App\Models\Document;
use App\Models\Vehicle;
use App\Models\Notification;


class DocumentNotificationService
{
    protected $fcmService;
    protected $notificationService;

    public function __construct(FCMService $fcmService, NotificationService $notificationService)
    {
        $this->fcmService = $fcmService;
        $this->notificationService = $notificationService;
    }

    
    public function sendDocumentApprovedNotification(Document $document): bool
    {
        try {
            $user = $this->getDocumentOwner($document);

            if (!$user) {
                
                return false;
            }

            if (!$user->isDriver()) {
                return true;
            }

            if (!$user->fcm_token) {
                
                return false;
            }

            $notification = $this->getDocumentApprovedNotificationContent($document, $user);
            $data = $this->getDocumentNotificationData($document, 'approved');

            $dbNotification = Notification::create([
                'user_id' => $user->id,
                'type' => 'document_status',
                'title' => $notification['title'],
                'body' => $notification['body'],
                'icon' => $notification['icon'],
                'sound' => $notification['sound'],
                'data' => $data,
                'status' => 'pending'
            ]);

            $result = $this->fcmService->sendToDevice($user->fcm_token, $notification, $data);

            if ($result['success'] ?? false) {
                $dbNotification->markAsSent($result['message_id'] ?? null);
            } else {
                $dbNotification->markAsFailed($result['error'] ?? 'FCM send failed');
            }

            $this->notificationService->sendDocumentNotification($user, $document, 'approved', $notification['body']);

            return $result['success'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    
    public function sendDocumentRejectedNotification(Document $document, string $rejectionReason): bool
    {
        try {
            $user = $this->getDocumentOwner($document);

            if (!$user) {
                
                return false;
            }

            if (!$user->isDriver()) {
                return true;
            }

            if (!$user->fcm_token) {
                
                return false;
            }

            $notification = $this->getDocumentRejectedNotificationContent($document, $user, $rejectionReason);
            $data = $this->getDocumentNotificationData($document, 'rejected', ['rejection_reason' => $rejectionReason]);

            $dbNotification = Notification::create([
                'user_id' => $user->id,
                'type' => 'document_status',
                'title' => $notification['title'],
                'body' => $notification['body'],
                'icon' => $notification['icon'],
                'sound' => $notification['sound'],
                'data' => $data,
                'status' => 'pending'
            ]);

            $result = $this->fcmService->sendToDevice($user->fcm_token, $notification, $data);

            if ($result['success'] ?? false) {
                $dbNotification->markAsSent($result['message_id'] ?? null);
            } else {
                $dbNotification->markAsFailed($result['error'] ?? 'FCM send failed');
            }

            $this->notificationService->sendDocumentNotification($user, $document, 'rejected', $notification['body']);

            

            return $result['success'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    
    public function sendDriverVerifiedNotification(User $driver): bool
    {
        try {
            if (!$driver->isDriver() || !$driver->fcm_token) {
                return false;
            }

            $notification = [
                'title' => 'Account Verified! ✅',
                'body' => 'Congratulations! All your documents have been approved. You can now go online and start accepting rides.',
                'icon' => 'ic_account_verified',
                'sound' => 'account_verified.mp3'
            ];

            $data = [
                'type' => 'account_verification',
                'status' => 'verified',
                'user_id' => $driver->id,
                'timestamp' => now()->timestamp
            ];

            $dbNotification = Notification::create([
                'user_id' => $driver->id,
                'type' => 'driver_verified',
                'title' => $notification['title'],
                'body' => $notification['body'],
                'icon' => $notification['icon'],
                'sound' => $notification['sound'],
                'data' => $data,
                'status' => 'pending'
            ]);

            $result = $this->fcmService->sendToDevice($driver->fcm_token, $notification, $data);

            if ($result['success'] ?? false) {
                $dbNotification->markAsSent($result['message_id'] ?? null);
            } else {
                $dbNotification->markAsFailed($result['error'] ?? 'FCM send failed');
            }

            

            return $result['success'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    
    protected function getDocumentOwner(Document $document): ?User
    {
        if ($document->documentable_type === User::class) {
            return $document->documentable;
        } elseif ($document->documentable_type === Vehicle::class) {
            $vehicle = $document->documentable;
            return $vehicle ? $vehicle->driver : null;
        }

        return null;
    }

    
    protected function getDocumentApprovedNotificationContent(Document $document, User $user): array
    {
        $documentTypeName = $this->getDocumentTypeName($document->document_type);

        return [
            'title' => 'Document Approved! ✅',
            'body' => "Your {$documentTypeName} has been approved successfully. Check your verification status in the app.",
            'icon' => 'ic_document_approved',
            'sound' => 'document_approved.mp3'
        ];
    }

    
    protected function getDocumentRejectedNotificationContent(Document $document, User $user, string $rejectionReason): array
    {
        $documentTypeName = $this->getDocumentTypeName($document->document_type);
        $reason = strlen($rejectionReason) > 50 ? substr($rejectionReason, 0, 50) . '...' : $rejectionReason;

        return [
            'title' => 'Document Update Required ❌',
            'body' => "Your {$documentTypeName} needs attention. Reason: {$reason}. Please resubmit with correct details.",
            'icon' => 'ic_document_rejected',
            'sound' => 'document_rejected.mp3'
        ];
    }

    
    protected function getDocumentNotificationData(Document $document, string $status, array $additionalData = []): array
    {
        return array_merge([
            'type' => 'document_status',
            'document_id' => $document->id,
            'document_type' => $document->document_type,
            'status' => $status,
            'timestamp' => now()->timestamp
        ], $additionalData);
    }

    
    protected function getDocumentTypeName(?string $documentType): string
    {
        if (empty($documentType)) {
            return 'Document';
        }

        return match ($documentType) {
            'license' => 'Driver License',
            'identity' => 'Identity Card',
            'address_proof' => 'Address Proof',
            'insurance' => 'Insurance Document',
            'registration' => 'Vehicle Registration',
            'permit' => 'Vehicle Permit',
            'fitness' => 'Fitness Certificate',
            'pollution' => 'Pollution Certificate',
            'other' => 'Document',
            default => ucwords(str_replace('_', ' ', $documentType))
        };
    }

    
    public function sendBulkDocumentNotifications(array $documentIds, string $status, string $reason = null): array
    {
        $results = [];

        foreach ($documentIds as $documentId) {
            $document = Document::find($documentId);

            if (!$document) {
                $results[$documentId] = ['success' => false, 'error' => 'Document not found'];
                continue;
            }

            if ($status === 'approved') {
                $results[$documentId] = ['success' => $this->sendDocumentApprovedNotification($document)];
            } elseif ($status === 'rejected') {
                $results[$documentId] = ['success' => $this->sendDocumentRejectedNotification($document, $reason ?? 'No reason provided')];
            }
        }

        return $results;
    }

    
    public function checkAndSendVerificationNotification(User $driver): bool
    {
        if (!$driver->isDriver()) {
            return false;
        }

        $driver->refresh(); // Refresh to get latest verification status

        if ($driver->is_verified && $driver->status === 'active') {
            return $this->sendDriverVerifiedNotification($driver);
        }

        return false;
    }
}
