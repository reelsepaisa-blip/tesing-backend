<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Otp;
use App\Services\Msg91Service;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BaseAuthController extends Controller
{
    protected $msg91Service;

    public function __construct()
    {
        $this->msg91Service = new Msg91Service();
    }
    protected function validatePhone(string $phone): void
    {
        $validator = Validator::make(['phone' => $phone], [
            'phone' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages([
                'phone' => ['Please enter a valid 10-digit phone number.'],
            ]);
        }
    }

    protected function generateOTP(): string
    {
        // In demo mode, return demo OTP (123456)
        if (\App\Services\DemoModeService::isEnabled()) {
            return \App\Services\DemoModeService::getDemoLoginOtp();
        }

        return Otp::generateOtp();
    }

    protected function sendOTP(string $phone, string $otp, string $countryCode = '+91', ?string $signatureId = null): bool
    {
        if (config('services.msg91.enabled', true)) {
            try {
                $result = $this->msg91Service->sendOtp($phone, $otp, $countryCode, $signatureId);

                if ($result) {
                    return true;
                } else {
                    return true; // Return true to not block the flow
                }
            } catch (\Exception $e) {
                return true; // Return true to not block the flow
            }
        }

        return true;
    }

    protected function createOtpRecord(string $phone, string $type = 'user_login', string $countryCode = '+91'): Otp
    {
        return Otp::createOtp($phone, $type, $countryCode);
    }

    protected function verifyOtpRecord(string $phone, string $otp, string $type = 'user_login'): ?Otp
    {
        return Otp::verifyOtp($phone, $otp, $type);
    }


    protected function verifyOtpRecordWithDetails(string $phone, string $otp, string $type = 'user_login'): array
    {
        return Otp::verifyOtpWithDetails($phone, $otp, $type);
    }

    protected function getValidOtpRecord(string $phone, string $type = 'user_login'): ?Otp
    {
        return Otp::getValidOtp($phone, $type);
    }

    protected function createAuthToken(User $user, string $deviceToken = '', ?string $loginDevice = null): array
    {
        $bearerToken = $user->isDriver()
            ? $this->generateBearerTokenWithDriverId($user->id)
            : $this->generateBearerToken();

        // Tokens don't expire for all users
        $tokenExpiresAt = null;

        $updates = [
            'bearer_token' => $this->hashBearerToken($bearerToken),
            'token_expires_at' => $tokenExpiresAt,
        ];

        if (!empty($deviceToken)) {
            $updates['device_token'] = $deviceToken;
        }

        if (!empty($loginDevice)) {
            $updates['login_device'] = $loginDevice;
        }

        $user->update($updates);
        $this->storeSanctumToken($user, $bearerToken);

        return [
            'token' => $bearerToken,
            'user' => $this->getUserResponse($user),
        ];
    }


    protected function generateBearerToken(): string
    {
        return 'Bearer_' . bin2hex(random_bytes(32));
    }


    protected function generateBearerTokenWithDriverId(int $driverId): string
    {
        $randomPart = bin2hex(random_bytes(32));
        $driverIdHex = dechex($driverId);
        return 'Bearer_' . $driverIdHex . '_' . $randomPart;
    }


    protected function extractDriverIdFromToken(string $token): ?int
    {
        $token = str_replace('Bearer_', '', $token);

        if (strpos($token, '_') !== false) {
            $parts = explode('_', $token, 2);
            if (count($parts) === 2 && is_numeric(hexdec($parts[0]))) {
                return (int) hexdec($parts[0]);
            }
        }

        return null;
    }


    protected function refreshBearerToken(User $user): string
    {
        $bearerToken = $user->isDriver()
            ? $this->generateBearerTokenWithDriverId($user->id)
            : $this->generateBearerToken();

        // Tokens don't expire for all users
        $tokenExpiresAt = null;

        $user->update([
            'bearer_token' => $this->hashBearerToken($bearerToken),
            'token_expires_at' => $tokenExpiresAt,
        ]);
        $this->storeSanctumToken($user, $bearerToken);

        return $bearerToken;
    }



    protected function validateBearerToken(string $token): ?User
    {
        $userFromSanctum = $this->findUserBySanctumToken($token);
        if ($userFromSanctum) {
            return $userFromSanctum;
        }

        $normalizedToken = $this->normalizeBearerToken($token);
        $hashedToken = $this->hashBearerToken($normalizedToken);

        return User::where(function ($query) use ($hashedToken, $normalizedToken) {
            $query->where('bearer_token', $hashedToken)
                // Backward compatibility for old plain-text tokens.
                ->orWhere('bearer_token', $normalizedToken)
                ->orWhere('bearer_token', 'Bearer_' . $normalizedToken);
        })
            ->where(function ($query) {
                // All users can have null token_expires_at (never expires) or valid future expiration
                $query->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '>', now());
            })
            ->first();
    }

    protected function normalizeBearerToken(string $token): string
    {
        return str_replace('Bearer_', '', $token);
    }

    protected function hashBearerToken(string $token): string
    {
        return hash('sha256', $this->normalizeBearerToken($token));
    }

    protected function storeSanctumToken(User $user, string $token): void
    {
        $normalizedToken = $this->normalizeBearerToken($token);

        // Keep single active API token behavior (same as previous bearer_token overwrite).
        $user->tokens()->delete();

        $user->tokens()->create([
            'name' => 'auth_token',
            'token' => hash('sha256', $normalizedToken),
            'abilities' => ['*'],
            'expires_at' => null,
        ]);
    }

    protected function findUserBySanctumToken(string $token): ?User
    {
        $normalizedToken = $this->normalizeBearerToken($token);
        $tokenHash = hash('sha256', $normalizedToken);

        $personalToken = \Laravel\Sanctum\PersonalAccessToken::query()
            ->where('token', $tokenHash)
            ->where('tokenable_type', User::class)
            ->first();

        if (!$personalToken || !$personalToken->tokenable instanceof User) {
            return null;
        }

        $user = $personalToken->tokenable;
        if (!$user->token_expires_at || $user->token_expires_at->isFuture()) {
            return $user;
        }

        return null;
    }

    protected function getUserResponse(User $user): array
    {
        $data = [
            'id' => (string) $user->id,
            'name' => $user->name ?? '',
            'phone' => $user->phone ?? '',
            'address' => $user->address ?? '',
            'country_code' => $user->country_code ?? '',
            'email' => $user->email ?? '',
            'gender' => $user->gender ?? '',
            'login_device' => $user->login_device ?? '',
            'role' => (string) $user->role_id,
            'profile_photo' => $this->getProfilePhotoUrl($user->profile_photo),
            'status' => $user->status ?? '',
            'referral_code' => $user->referral_code ?? '',
            'wallet_balance' => (string) ($user->wallet?->balance ?? 0),
            'is_register' => (string) ($user->is_register ?? 0),
            'step_0' => (string) ($user->step_0 ?? 0),
            'step_1' => (string) ($user->step_1 ?? 0),
            'step_2' => (string) ($user->step_2 ?? 0),
            'step_3' => (string) ($user->step_3 ?? 0),
            'saved_locations' => $this->getSavedLocations($user),
        ];

        if ($user->isDriver()) {
            $data['driver'] = [
                'is_verified' => (string) ($user->driverProfile?->isVerified() ?? false),
                'city' => $user->driverProfile?->city?->name ?? '',
                'rating' => (string) ($user->driverProfile?->rating ?? 0),
                'total_trips' => (string) ($user->driverProfile?->total_trips ?? 0),
                'is_online' => (string) ($user->is_online ?? false),
                'documents_status' => $this->getDocumentsStatus($user),
            ];
        }

        return $data;
    }

    protected function getDocumentsStatus(User $user): array
    {
        $requiredTypes = \App\Models\Document::getDriverRequiredTypes();
        $documents = $user->documents()->whereIn('type', $requiredTypes)->get();

        $status = [
            'total' => (string) count($requiredTypes),
            'uploaded' => (string) $documents->count(),
            'approved' => (string) $documents->where('status', 'approved')->count(),
            'pending' => (string) $documents->where('status', 'pending')->count(),
            'rejected' => (string) $documents->where('status', 'rejected')->count(),
        ];

        $status['is_complete'] = (string) ($status['uploaded'] === $status['total'] && $status['pending'] === '0');

        return $status;
    }

    protected function getSavedLocations(User $user): array
    {
        $savedLocations = \App\Models\SavedLocation::where('user_id', $user->id)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return $savedLocations->map(function ($location) {
            return [
                'id' => (string) $location->id,
                'name' => $location->name ?? '',
                'address' => $location->address ?? '',
                'latitude' => (string) $location->latitude,
                'longitude' => (string) $location->longitude,
                'type' => $location->type ?? 'custom',
                'is_default' => (string) ($location->is_default ? '1' : '0'),
                'meta_data' => $location->meta_data ?? [],
                'created_at' => $location->created_at?->toISOString() ?? '',
                'updated_at' => $location->updated_at?->toISOString() ?? '',
            ];
        })->toArray();
    }

    protected function validateRegistration(array $data, bool $isDriver = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^[0-9]{10}$/', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'device_token' => ['nullable', 'string'],
            'referral_code' => ['nullable', 'string', 'exists:users,referral_code'],
        ];

        if ($isDriver) {
            $rules = array_merge($rules, [
                'city_id' => ['required', 'exists:cities,id'],
                'address' => ['required', 'string'],
                'license_number' => ['required', 'string', 'unique:driver_profiles,license_number'],
                'identity_number' => ['required', 'string', 'unique:driver_profiles,identity_number'],
                'identity_type' => ['required', 'in:aadhar,pan,voter_id'],
            ]);
        }

        return Validator::make($data, $rules)->validate();
    }


    protected function sendFirebaseNotification(string $deviceToken, array $data, array $notification = []): bool
    {
        try {
            $accessToken = $this->getFirebaseAccessToken();
            $projectId = $this->getFirebaseProjectId();

            if (!$accessToken || !$projectId) {
                return false;
            }

            $payload = [
                'message' => [
                    'token' => $deviceToken,
                    'data' => $this->normalizeFirebaseData($data),
                    'android' => [
                        'priority' => 'HIGH',
                    ],
                ],
            ];

            if (!empty($notification)) {
                $payload['message']['notification'] = array_filter([
                    'title' => $notification['title'] ?? null,
                    'body' => $notification['body'] ?? null,
                    'image' => $notification['image'] ?? null,
                ], fn($value) => !is_null($value) && $value !== '');
            }

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", $payload);

            if (!$response->successful()) {
                Log::warning('FCM v1 notification failed.', [
                    'status' => $response->status(),
                    'response' => $response->json() ?? $response->body(),
                ]);

                return false;
            }

            Log::info('FCM v1 notification sent successfully.', [
                'project_id' => $projectId,
                'fcm_message_name' => data_get($response->json(), 'name'),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('FCM v1 notification exception.', [
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function getFirebaseAccessToken(): ?string
    {
        $credentialsPath = config('services.firebase.credentials');

        if (!$credentialsPath || !is_file($credentialsPath)) {
            Log::warning('Firebase service account credentials file not found.', [
                'path' => $credentialsPath,
            ]);
            return null;
        }

        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
        $credentials = new ServiceAccountCredentials($scopes, $credentialsPath);
        $token = $credentials->fetchAuthToken();

        return $token['access_token'] ?? null;
    }

    protected function getFirebaseProjectId(): ?string
    {
        $projectId = config('services.fcm.project_id');

        if (!empty($projectId)) {
            return $projectId;
        }

        $credentialsPath = config('services.firebase.credentials');

        if (!$credentialsPath || !is_file($credentialsPath)) {
            return null;
        }

        $credentialsJson = json_decode((string) file_get_contents($credentialsPath), true);

        return $credentialsJson['project_id'] ?? null;
    }

    protected function normalizeFirebaseData(array $data): array
    {
        return collect($data)
            ->mapWithKeys(function ($value, $key) {
                $normalizedValue = is_scalar($value) || is_null($value)
                    ? (string) ($value ?? '')
                    : json_encode($value);

                return [(string) $key => (string) $normalizedValue];
            })
            ->toArray();
    }


    protected function sendOtpViaFirebase(string $phone, string $otp, string $deviceToken = null, string $countryCode = '+91', ?string $signatureId = null): bool
    {
        if ($deviceToken) {
            $signatureText = $signatureId ? " and signature_id is $signatureId" : "";
            $notification = [
                'title' => 'OTP Verification',
                'body' => "Your OTP is: $otp$signatureText. Valid for 5 minutes.",
                'sound' => 'default',
            ];

            $data = [
                'type' => 'otp',
                'phone' => $phone,
                'otp' => $otp,
                'country_code' => $countryCode,
                'expires_in' => '300', // 5 minutes in seconds
            ];

            if ($signatureId) {
                $data['signature_id'] = $signatureId;
            }

            return $this->sendFirebaseNotification($deviceToken, $data, $notification);
        }

        return $this->sendOTP($phone, $otp, $countryCode, $signatureId);
    }


    protected function getProfilePhotoUrl(?string $profilePhoto): string
    {
        if (empty($profilePhoto)) {
            return '';
        }

        if (filter_var($profilePhoto, FILTER_VALIDATE_URL)) {
            return $profilePhoto;
        }

        return url('storage/' . $profilePhoto);
    }
}
