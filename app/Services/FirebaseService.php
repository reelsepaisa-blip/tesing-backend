<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Kreait\Firebase\Exception\Auth\EmailNotFound;
use Exception;

class FirebaseService
{
    protected $messaging;
    protected $auth;
    protected $enabled;
    protected $factory;

    public function __construct()
    {
        $this->enabled = true;

        try {
            $credentialsPath = config('services.firebase.credentials', base_path('public/e-taxi-649bb-firebase-adminsdk-fbsvc.json'));
            $databaseUrl = config('services.firebase.database_url', 'https://e-taxi-649bb.firebaseio.com');

            if (!class_exists('Kreait\Firebase\Factory')) {

                $this->enabled = false;
                return;
            }

            $this->factory = new Factory();

            if ($credentialsPath && file_exists($credentialsPath)) {
                $this->factory = $this->factory->withServiceAccount($credentialsPath);
            } else {

                $this->enabled = false;
                return;
            }

            if ($databaseUrl) {
                $this->factory = $this->factory->withDatabaseUri($databaseUrl);
            }

            $this->messaging = $this->factory->createMessaging();
            $this->auth = $this->factory->createAuth();
        } catch (Exception $e) {
            $this->enabled = false;
        }
    }


    public function sendNotification($deviceToken, $title, $body, $data = [])
    {
        if (!$this->enabled || !$this->messaging) {
            throw new Exception('Firebase service is disabled or not initialized');
        }

        try {
            $notification = Notification::create($title, $body);

            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification($notification)
                ->withData($data);

            $result = $this->messaging->send($message);


            return $result;
        } catch (MessagingException $e) {
            throw $e;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Delete a Firebase user by UID
     *
     * @param string $uid Firebase user UID
     * @return bool True if successful, false otherwise
     */
    public function deleteUserByUid(string $uid): bool
    {
        if (!$this->enabled || !$this->auth) {

            return false;
        }

        try {
            $this->auth->deleteUser($uid);

            return true;
        } catch (UserNotFound $e) {
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete a Firebase user by email
     *
     * @param string $email User email address
     * @return bool True if successful, false otherwise
     */
    public function deleteUserByEmail(string $email): bool
    {
        if (!$this->enabled || !$this->auth) {

            return false;
        }

        if (empty($email)) {

            return false;
        }

        try {
            $user = $this->auth->getUserByEmail($email);
            $this->auth->deleteUser($user->uid);

            return true;
        } catch (EmailNotFound $e) {
            return false;
        } catch (UserNotFound $e) {

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete a Firebase user by phone number
     *
     * @param string $phone Phone number (with or without country code)
     * @return bool True if successful, false otherwise
     */
    public function deleteUserByPhone(string $phone): bool
    {
        if (!$this->enabled || !$this->auth) {

            return false;
        }

        if (empty($phone)) {

            return false;
        }

        // Normalize phone number (remove spaces, dashes, etc.)
        $normalizedPhone = preg_replace('/[^0-9+]/', '', $phone);

        // Ensure phone starts with + for Firebase
        if (!str_starts_with($normalizedPhone, '+')) {
            $normalizedPhone = '+' . $normalizedPhone;
        }

        try {

            $user = $this->auth->getUserByPhoneNumber($normalizedPhone);
            $this->auth->deleteUser($user->uid);

            return true;
        } catch (UserNotFound $e) {
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete a Firebase user by UID, email, or phone
     *
     * @param string|null $firebaseUid Firebase user UID (preferred method)
     * @param string|null $email User email address (fallback)
     * @param string|null $phone User phone number (ignored, kept for backward compatibility)
     * @return bool True if successful, false otherwise
     */
    public function deleteUser(?string $firebaseUid = null, ?string $email = null, ?string $phone = null): bool
    {
        if (!$this->enabled || !$this->auth) {

            return false;
        }

        // Try Firebase UID first (most reliable)
        if (!empty($firebaseUid)) {
            if ($this->deleteUserByUid($firebaseUid)) {
                return true;
            }
        }

        // Fallback to email if UID not provided or not found
        if (!empty($email)) {
            return $this->deleteUserByEmail($email);
        }

        // If both are empty, skip Firebase deletion


        return false;
    }
}
