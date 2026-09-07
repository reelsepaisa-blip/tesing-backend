<?php

namespace App\Http\Controllers\Api\Auth;

use App\Models\Booking;
use App\Models\Document;
use App\Models\DocumentList;
use App\Models\DriverDocument;
use App\Models\DriverIncentive;
use App\Models\DriverLocation;
use App\Models\DriverProfile;
use App\Models\SystemConfiguration;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CityService;
use App\Services\DriverAutoLocationService;
use App\Services\ETAService;
use App\Services\GeocodingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DriverAuthController extends BaseAuthController
{
    protected $driverAutoLocationService;
    protected $cityService;

    public function __construct(DriverAutoLocationService $driverAutoLocationService, CityService $cityService)
    {
        parent::__construct();
        $this->driverAutoLocationService = $driverAutoLocationService;
        $this->cityService = $cityService;
    }

    public function sendOTPForLogin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'phone' => ['required', 'string'],
                'country_code' => ['nullable', 'string', 'regex:/^\+[1-9]\d{0,3}$/', 'max:5'],
                'device_token' => ['nullable', 'string'],
                'signature' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
            ], 422);
        }

        $phone = $request->input('phone');
        $countryCode = $request->input('country_code', '+91');
        $deviceToken = $request->input('device_token');
        $signature = $request->input('signature');

        $authData = null;
        $user = User::withTrashed()
            ->where('phone', $phone)
            ->where('role_id', 2)
            ->first();

        $wasSoftDeleted = false;

        if (!$user) {
            $user = User::create([
                'phone' => $phone,
                'country_code' => $countryCode,
                'role_id' => 2,
                'status' => 'incomplete',
                'device_token' => $deviceToken,
                'phone_verified_at' => null,
            ]);

            $authData = $this->createAuthToken($user, $deviceToken ?? '', 'phone');
        } else {
            if ($user->trashed()) {
                $user->restore();
                $wasSoftDeleted = true;
            }

            $user->fill([
                'country_code' => $countryCode,
            ]);

            if (!empty($deviceToken)) {
                $user->device_token = $deviceToken;
            }

            if ($wasSoftDeleted) {
                $user->status = 'incomplete';
                $user->phone_verified_at = null;
            }

            if ($user->isDirty()) {
                $user->save();
            }

            if ($wasSoftDeleted) {
                $authData = $this->createAuthToken($user, $deviceToken ?? '', 'phone');
            }
        }

        $user->refresh();

        $otpRecord = $this->createOtpRecord($phone, 'driver_login', $countryCode);

        if ($deviceToken) {
            $this->sendOtpViaFirebase($phone, $otpRecord->otp, $deviceToken, $countryCode, $signature);
        } else {
            $this->sendOTP($phone, $otpRecord->otp, $countryCode, $signature);
        }

        $responseData = [
            'phone' => $phone,
            'country_code' => $countryCode,
            'expires_at' => $otpRecord->expires_at->format('Y-m-d H:i:s'),
        ];

        if ($signature !== null) {
            $responseData['signature'] = $signature;
        }

        $response = [
            'success' => true,
            'message' => 'OTP sent successfully',
            'expires_in' => '1 minutes',
            'otp' => $otpRecord->otp,  // Remove this in production
            'data' => $responseData,
        ];

        if ($user->status === 'incomplete') {
            $response['bearer_token'] = $user->bearer_token;
            $response['step'] = 0;
            $response['next_step'] = 1;
        }

        return response()->json($response);
    }

    public function loginWithOTP(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'phone' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
                'country_code' => ['nullable', 'string', 'regex:/^\+[1-9]\d{0,3}$/', 'max:5'],
                'device_token' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
            ], 422);
        }

        $phone = $request->input('phone');
        $countryCode = $request->input('country_code', '+91');
        $deviceToken = $request->input('device_token');

        $user = User::where('phone', $phone)
            ->where('role_id', 2)  // Driver role
            ->first();

        if (!$user) {
            // Create new driver
            $user = User::create([
                'phone' => $phone,
                'country_code' => $countryCode,
                'role_id' => 2,
                'status' => 'under_review',  // or under_review as per flow
                'device_token' => $deviceToken,
            ]);
        } else {
            // Update existing driver
            $user->update([
                'country_code' => $countryCode,
                'device_token' => $deviceToken,
            ]);
        }

        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been blocked. Please contact support.',
            ], 422);
        }

        $authData = $this->createAuthToken($user, $deviceToken ?? '', 'phone', $countryCode);

        if ($user->status === 'under_review') {
            return response()->json([
                'success' => false,
                'message' => 'Your Document is in Under Review',
                'token' => $authData['token'],
                'driver' => $this->getDriverResponse($user),
                'document_data' => $this->getDocumentData($user),
            ], 200);
        }

        if ($user->status === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Your document is rejected please upload again',
                'token' => $authData['token'],
                'driver' => $this->getDriverResponse($user),
                'document_data' => $this->getDocumentData($user),
            ], 200);
        }

        $hasPendingDocuments = $this->driverHasDocumentsWithStatus($user, ['pending']);
        $hasRejectedDocuments = $this->driverHasDocumentsWithStatus($user, ['rejected']);

        if ($hasPendingDocuments) {
            return response()->json([
                'success' => false,
                'message' => 'Your Document is in Under Review',
                'token' => $authData['token'],
                'driver' => $this->getDriverResponse($user),
                'document_data' => $this->getDocumentData($user),
            ], 200);
        }

        if ($hasRejectedDocuments) {
            return response()->json([
                'success' => false,
                'message' => 'Your document is rejected please upload again',
                'token' => $authData['token'],
            ], 200);
        }

        $driverResponse = $this->getDriverResponse($user);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $authData['token'],
            'driver' => $driverResponse,
            'document_data' => $this->getDocumentData($user),
        ]);
    }

    private function getOtpAttemptCacheKey(string $phone, string $countryCode): string
    {
        return 'driver_login_otp_attempts:' . $countryCode . ':' . $phone;
    }

    private function getOtpBlockCacheKey(string $phone, string $countryCode): string
    {
        return 'driver_login_otp_block:' . $countryCode . ':' . $phone;
    }

    public function register(Request $request): JsonResponse
    {
        $step = $request->input('step', 1);  // Default to step 1

        try {
            switch ($step) {
                case 0:
                    $data = $this->validateStep0($request->all());
                    break;
                case 1:
                    $data = $this->validateStep1($request->all(), $request);
                    break;
                case 2:
                    $data = $this->validateStep2($request->all(), $request);
                    break;
                case 3:
                    $data = $this->validateStep3($request->all());
                    break;
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid step provided.',
                    ], 422);
            }
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
                'errors' => $errors,  // Include full errors object for detailed field-level error display
            ], 422);
        }

        try {
            DB::beginTransaction();

            $user = null;
            $vehicle = null;

            switch ($step) {
                case 0:
                    $user = $this->handleStep0($request, $data);
                    break;
                case 1:
                    $user = $this->getAuthenticatedUser($request);
                    $user = $this->handleStep1($request, $data, $user);
                    break;
                case 2:
                    $user = $this->getAuthenticatedUser($request);
                    $vehicle = $this->handleStep2($request, $data, $user);
                    break;
                case 3:
                    $user = $this->getAuthenticatedUser($request);
                    $vehicle = $user->vehicles()->first();
                    if (!$vehicle) {
                        throw new \Exception('Vehicle not found. Please complete step 2 first.');
                    }
                    $this->handleStep3($request, $data, $user, $vehicle);
                    break;
            }

            DB::commit();

            $response = [
                'success' => true,
                'step' => $step,
                'driver' => $this->getDriverResponse($user),
            ];

            if ($step == 0) {
                $response['message'] = 'Location saved successfully.';
                $response['next_step'] = 1;
                $response['token'] = $user->bearer_token;

                if (isset($data['city_name'])) {
                    $response['city_name'] = $data['city_name'];
                    $response['city_id'] = $data['city_id'];
                }
            } elseif ($step == 1) {
                $response['message'] = 'Profile created successfully.';
                $response['next_step'] = 2;
                $response['token'] = $user->bearer_token;
            } elseif ($step == 2) {
                $response['message'] = 'Vehicle information saved successfully.';
                $response['next_step'] = 3;
                $response['vehicle'] = [
                    'id' => (string) $vehicle->id,
                    'brand' => $vehicle->brand,
                    'model' => $vehicle->model,
                    'year' => (string) $vehicle->year,
                    'registration_number' => $vehicle->registration_number,
                    'ride_type' => $vehicle->rideType?->name ?? '',
                ];
            } else {
                $response['message'] = 'Documents uploaded successfully. Registration completed!';
                $response['completed'] = true;
                $response['vehicle'] = [
                    'id' => (string) $vehicle->id,
                    'brand' => $vehicle->brand,
                    'model' => $vehicle->model,
                    'year' => (string) $vehicle->year,
                    'registration_number' => $vehicle->registration_number,
                    'ride_type' => $vehicle->rideType?->name ?? '',
                ];

                $response['uploaded_documents'] = $this->getUploadedDocumentsInfo($user, $vehicle);
            }

            return response()->json($response);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();

            if ($e->getCode() == 23000) {  // MySQL duplicate entry error
                if (str_contains($e->getMessage(), 'users_phone_role_unique')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This phone number is already registered as a driver.',
                    ], 422);
                }
                if (str_contains($e->getMessage(), 'users_email_unique')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This email address is already registered.',
                    ], 422);
                }
                if (str_contains($e->getMessage(), 'driver_profiles_license_number_unique')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This license number is already registered.',
                    ], 422);
                }
                if (str_contains($e->getMessage(), 'driver_profiles_identity_number_unique')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This identity number is already registered.',
                    ], 422);
                }
                if (str_contains($e->getMessage(), 'vehicles_registration_number_unique')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This vehicle registration number is already registered.',
                    ], 422);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $normalizedType = null;
        $allowedTypes = Document::getDriverRequiredTypes();

        try {
            $request->validate([
                'file_front' => ['nullable', 'file', 'max:5120'],  // 5MB max
                'file_back' => ['nullable', 'file', 'max:5120'],
                'type' => [
                    'required',
                    'string',
                    function ($attribute, $value, $fail) use ($allowedTypes, &$normalizedType) {
                        $normalizedType = Document::normalizeDocumentType($value);

                        if (!$normalizedType || !in_array($normalizedType, $allowedTypes, true)) {
                            $fail('The selected document type is invalid.');
                        }
                    }
                ],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
            ], 422);
        }

        $user = $request->user();
        $documentType = $normalizedType ?? Document::normalizeDocumentType($request->type);

        $frontPath = $request->file('file_front')->store("driver-documents/{$user->id}", 'public');
        $backPath = null;
        if ($request->hasFile('file_back')) {
            $backPath = $request->file('file_back')->store("driver-documents/{$user->id}", 'public');
        }

        $existingDocument = $user->documents()->where('type', $documentType)->first();

        if ($existingDocument) {
            $updateData = [
                'file_front' => $frontPath,
                'file_back' => $backPath,
            ];

            if ($existingDocument->status === 'rejected') {
                $updateData['status'] = 'pending';
                $updateData['rejection_reason'] = null;
            }

            $existingDocument->update($updateData);
            $document = $existingDocument->fresh();
        } else {
            $document = $user->documents()->create([
                'type' => $documentType ?? '',
                'file_front' => $frontPath,
                'file_back' => $backPath,
                'status' => 'pending',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Document uploaded successfully',
            'document' => [
                'id' => (string) $document->id,
                'documentable_id' => (string) $document->documentable_id,
                'documentable_type' => $document->documentable_type,
                'type' => $document->type ?? '',
                'number' => $document->number ?? '',
                'file_front' => $document->file_front ?? '',
                'file_back' => $document->file_back ?? '',
                'expiry_date' => $document->expiry_date ? (is_string($document->expiry_date) ? $document->expiry_date : $document->expiry_date->format('Y-m-d')) : '',
                'status' => $document->status ?? '',
                'rejection_reason' => $document->rejection_reason ?? '',
                'verified_at' => $document->verified_at?->toISOString() ?? '',
                'verified_by' => $document->verified_by ? (string) $document->verified_by : '',
                'meta_data' => $document->meta_data ?? [],
                'created_at' => $document->created_at?->toISOString() ?? '',
                'updated_at' => $document->updated_at?->toISOString() ?? '',
            ],
        ]);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'heading' => ['nullable', 'numeric', 'between:0,360'],
                'speed' => ['nullable', 'numeric', 'min:0'],
                'accuracy' => ['nullable', 'numeric', 'min:0'],
                'battery_level' => ['nullable', 'integer', 'between:0,100'],
                'is_charging' => ['nullable', 'boolean'],
                'address' => ['nullable', 'string', 'max:500'],
                'zone_id' => ['nullable', 'exists:zones,id'],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
            ], 422);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
                'error' => 'Invalid or missing authentication token'
            ], 401);
        }

        if ($user->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied',
                'error' => 'Only drivers can update location'
            ], 403);
        }

        // 🚫 Autogenerated driver check — HARD STOP
        // Autogenerated drivers have email pattern: {cityname}driver@etaxi.com
        if ($this->isAutogeneratedDriver($user)) {
            

            return response()->json([
                'success' => true,
                'message' => 'Autogenerated driver location update ignored'
            ], 200);
        }

        $user->update([
            'last_latitude' => $request->latitude,
            'last_longitude' => $request->longitude,
            'last_location_at' => now(),
            'updated_at' => now(),
        ]);

        $address = $request->address;
        if (empty($address)) {
            $geocodingService = app(GeocodingService::class);
            $address = $geocodingService->getAddressFromCoordinates($request->latitude, $request->longitude);
        }

        try {
            $locationData = [
                'driver_id' => $user->id,
                'zone_id' => $request->zone_id ?? 1,  // Default zone
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'address' => $address ?? NULL,
                'heading' => $request->heading ?? null,
                'speed' => $request->speed ?? null,
                'accuracy' => $request->accuracy ?? null,
                'battery_level' => $request->battery_level ?? null,
                'is_charging' => $request->is_charging ?? false,
                'is_active' => true,
                'recorded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $existingRecord = DB::table('driver_locations')
                ->where('driver_id', $user->id)
                // ->where('is_active', true)
                ->first();

            if ($existingRecord) {
                DB::table('driver_locations')
                    ->where('id', $existingRecord->id)
                    ->update([
                        'zone_id' => $locationData['zone_id'],
                        'latitude' => $locationData['latitude'],
                        'longitude' => $locationData['longitude'],
                        'address' => $locationData['address'],
                        'heading' => $locationData['heading'],
                        'speed' => $locationData['speed'],
                        'accuracy' => $locationData['accuracy'],
                        'battery_level' => $locationData['battery_level'],
                        'is_charging' => $locationData['is_charging'],
                        'recorded_at' => $locationData['recorded_at'],
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('driver_locations')->insert($locationData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Location updated successfully',
                'data' => [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'heading' => $request->heading ?? '',
                    'address' => $address ?? '',
                    'zone_id' => $request->zone_id ?? 1,
                    'recorded_at' => now()->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => true,
                'message' => 'Location updated successfully (driver_location update failed)',
                'warning' => 'Location saved to user table only',
                'data' => [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'recorded_at' => now()->format('Y-m-d H:i:s'),
                ]
            ]);
        }
    }

    public function toggleOnlineStatus(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'heading' => ['nullable', 'numeric', 'between:0,360'],
                'address' => ['nullable', 'string', 'max:500'],
                'zone_id' => ['nullable', 'exists:zones,id'],
                'enable_auto_location' => ['nullable', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
            ], 422);
        }

        $user = $request->user();

        if (!$user->canGoOnline()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot go online. Please complete profile and document verification.',
            ], 422);
        }

        $wasOnline = $user->is_online;
        $user->update([
            'is_online' => !$user->is_online,
            'last_location_at' => $user->is_online ? null : now(),
        ]);

        if ($user->is_online && !$wasOnline) {
            if ($request->has('latitude') && $request->has('longitude')) {
                $this->updateDriverLocation($user, $request);
            }

            $enableAutoLocation = $request->input('enable_auto_location', true);
            if ($enableAutoLocation) {
                $this->startAutoLocationUpdates($user);
            }
        } elseif (!$user->is_online && $wasOnline) {
            $this->stopAutoLocationUpdates($user);
        }

        return response()->json([
            'success' => true,
            'message' => $user->is_online ? 'You are now online' : 'You are now offline',
            'is_online' => $user->is_online,
            'auto_location_enabled' => $user->is_online ? $this->isAutoLocationActive($user) : false,
            'location_update_interval' => 10,  // seconds
        ]);
    }

    public function loginWithPassword(Request $request): JsonResponse
    {
        $authType = $this->determineDriverAuthType($request);
        switch ($authType) {
            case 'email_password':
                return $this->handleDriverEmailPasswordLogin($request);
            case 'email_only':
                return $this->handleDriverEmailOnlyRegistration($request);
            case 'google':
                return $this->handleDriverGoogleLogin($request);
            case 'apple':
                return $this->handleDriverAppleLogin($request);
            case 'email':
                return $this->handleDriverOtherEmailLogin($request);
            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid authentication method. Please provide email/password or email with auth_provider.',
                ], 422);
        }
    }

    private function determineDriverAuthType(Request $request): string
    {
        if ($request->has('auth_provider') && $request->auth_provider === 'apple' && $request->has('id')) {
            return 'apple';
        }

        if ($request->has('email') && $request->has('password') && $request->has('auth_provider')) {
            $provider = strtolower(trim($request->auth_provider));
            if ($provider === 'email') {
                return 'email_password';
            }
        }

        if ($request->has('email') && $request->has('auth_provider') && !empty($request->auth_provider)) {
            $provider = strtolower(trim($request->auth_provider));
            if (in_array($provider, ['google', 'apple'])) {
                return $provider;
            }
        }

        if ($request->has('email') && $request->has('password')) {
            return 'email_password';
        }

        if ($request->has('email') && !$request->has('password')) {
            return 'email_only';
        }

        return 'unknown';
    }

    private function handleDriverEmailPasswordLogin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],  // Password is now required for this login type
                'auth_provider' => ['required', 'string'],  // Auth provider is now required
                'device_token' => ['nullable', 'string'],
                'firebase_uid' => ['nullable', 'string'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'heading' => ['nullable', 'numeric', 'between:0,360'],
                'address' => ['nullable', 'string', 'max:500'],
                'zone_id' => ['nullable', 'exists:zones,id'],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
            ], 422);
        }

        $user = User::where('email', $request->email)
            ->where('role_id', 2)  // Driver role
            ->first();

        $isNewUser = false;
        $authData = null;
        $authData = null;
        $authData = null;

        if (!$user) {
            $userData = [
                'name' => null,  // Will be filled in Step 1
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role_id' => 2,  // Driver role
                'status' => 'incomplete',  // Mark as incomplete for Step 0 flow
                'email_verified_at' => now(),
                'device_token' => $request->device_token ?? '',
            ];

            // Store firebase_uid if provided
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $userData['firebase_uid'] = $request->firebase_uid;
            }

            $user = User::create($userData);

            $authData = $this->createAuthToken($user, $request->device_token ?? '', 'email');

            $isNewUser = true;
        } else {
            $updateData = [
                'password' => Hash::make($request->password),
            ];

            // Update firebase_uid if provided
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $updateData['firebase_uid'] = $request->firebase_uid;
            }

            $user->update($updateData);
        }

        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been blocked. Please contact support.',
            ], 422);
        }

        if (!$isNewUser) {
            $authData = $authData ?? $this->createAuthToken($user, $request->device_token ?? '', 'email');

            if ($user->status === 'under_review') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your Document is in Under Review',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            if ($user->status === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your document is rejected please upload again',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            $hasPendingDocuments = $this->driverHasDocumentsWithStatus($user, ['pending']);
            $hasRejectedDocuments = $this->driverHasDocumentsWithStatus($user, ['rejected']);

            if ($hasPendingDocuments) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your Document is in Under Review',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            if ($hasRejectedDocuments) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your document is rejected please upload again',
                    'token' => $authData['token'],
                ], 200);
            }
        }

        $authData = $authData ?? $this->createAuthToken($user, $request->device_token ?? '', 'email');

        $needsRegistration = $this->driverNeedsRegistration($user);

        if ($request->has('latitude') && $request->has('longitude')) {
            $this->updateDriverLocation($user, $request);
        }

        $driverData = $this->getDriverResponse($user);
        $driverData['is_email'] = 1;  // Email/password login
        $driverData['new_register'] = $needsRegistration ? 1 : 0;  // 1 if needs registration, 0 if complete

        $isVerify = true;
        if ($user->status === 'under_review' || $user->status === 'rejected') {
            $isVerify = false;
        } else {
            $hasPendingDocuments = $this->driverHasDocumentsWithStatus($user, ['pending']);
            $hasRejectedDocuments = $this->driverHasDocumentsWithStatus($user, ['rejected']);
            if ($hasPendingDocuments || $hasRejectedDocuments) {
                $isVerify = false;
            }
        }
        $driverData['is_verify'] = $isVerify;

        $response = [
            'success' => true,
            'message' => 'Login successful',
            'token' => $authData['token'],
            'driver' => $driverData,
            'document_data' => $this->getDocumentData($user),
        ];

        if ($isNewUser && $user->status === 'incomplete') {
            $response['step'] = 0;
            $response['next_step'] = 1;
            $response['message'] = 'Account created successfully. Please complete Step 0 to continue.';
        }

        return response()->json($response);
    }

    private function handleDriverEmailOnlyRegistration(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
                'device_token' => ['nullable', 'string'],
                'firebase_uid' => ['nullable', 'string'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'heading' => ['nullable', 'numeric', 'between:0,360'],
                'address' => ['nullable', 'string', 'max:500'],
                'zone_id' => ['nullable', 'exists:zones,id'],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
            ], 422);
        }

        $user = User::where('email', $request->email)
            ->where('role_id', 2)  // Driver role
            ->first();

        if (!$user) {
            $userData = [
                'name' => null,  // Will be filled in Step 1
                'email' => $request->email,
                'password' => null,  // No password set
                'role_id' => 2,  // Driver role
                'status' => 'incomplete',  // Mark as incomplete for Step 0 flow
                'email_verified_at' => now(),
                'device_token' => $request->device_token ?? '',
            ];

            // Store firebase_uid if provided
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $userData['firebase_uid'] = $request->firebase_uid;
            }

            $user = User::create($userData);

            $authData = $this->createAuthToken($user, $request->device_token ?? '', 'email');

            $needsRegistration = $this->driverNeedsRegistration($user);

            $driverData = $this->getDriverResponse($user);
            $driverData['is_email'] = 1;  // Email registration
            $driverData['new_register'] = 1;  // New user created

            return response()->json([
                'success' => true,
                'message' => 'Driver created successfully. Please complete Step 0 to continue.',
                'token' => $authData['token'],
                'driver' => $driverData,
                'step' => 0,
                'next_step' => 1,
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Driver already exists with this email address.',
            ], 422);
        }
    }

    private function handleDriverGoogleLogin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
                'password' => ['nullable', 'string'],  // Password is optional for social login
                'name' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],
                'profile_photo' => ['nullable', 'url'],
                'device_token' => ['nullable', 'string'],
                'firebase_uid' => ['nullable', 'string'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'heading' => ['nullable', 'numeric', 'between:0,360'],
                'address' => ['nullable', 'string', 'max:500'],
                'zone_id' => ['nullable', 'exists:zones,id'],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
            ], 422);
        }

        $email = trim(strtolower($request->email));
        $user = null;

        if (!empty($email)) {
            $user = User::withoutGlobalScopes()
                ->withTrashed()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->where('role_id', 2)  // Driver role
                ->first();

            if (!$user) {
                $user = User::withoutGlobalScopes()
                    ->withTrashed()
                    ->where('email', $request->email)
                    ->where('role_id', 2)
                    ->first();
            }

            if (!$user) {
                $user = User::withoutGlobalScopes()
                    ->withTrashed()
                    ->where('email', 'LIKE', $request->email)
                    ->where('role_id', 2)
                    ->first();
            }
        }

        if (!$user && $request->has('phone') && !empty($request->phone)) {
            $phone = trim($request->phone);
            $countryCode = $request->has('country_code') ? trim($request->country_code) : null;

            $phoneQuery = User::withoutGlobalScopes()
                ->withTrashed()
                ->where('phone', $phone)
                ->where('role_id', 2);

            if ($countryCode) {
                $phoneQuery->where('country_code', $countryCode);
            }

            $user = $phoneQuery->first();
        }

        $isNewUser = false;
        $authData = null;

        if (!$user) {
            $existingUser = null;

            if (!empty(trim($request->email))) {
                $existingUser = DB::table('users')
                    ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($request->email))])
                    ->where('role_id', 2)
                    ->whereNull('deleted_at')
                    ->first();
            }

            if (!$existingUser && $request->has('phone') && !empty(trim($request->phone))) {
                $phoneQuery = DB::table('users')
                    ->where('phone', trim($request->phone))
                    ->where('role_id', 2)
                    ->whereNull('deleted_at');

                if ($request->has('country_code') && !empty(trim($request->country_code))) {
                    $phoneQuery->where('country_code', trim($request->country_code));
                }

                $existingUser = $phoneQuery->first();
            }

            if ($existingUser) {
                $user = User::withoutGlobalScopes()
                    ->withTrashed()
                    ->find($existingUser->id);
            } else {
                try {
                    $userData = [
                        'name' => $request->name ?? null,  // Will be filled in Step 1 if not provided
                        'email' => $request->email,
                        'phone' => $request->phone,  // Optional phone for social login
                        'password' => $request->password ? Hash::make($request->password) : null,  // Store password if provided
                        'role_id' => 2,  // Driver role
                        'status' => 'incomplete',  // Mark as incomplete for Step 0 flow
                        'email_verified_at' => now(),  // Google accounts are pre-verified
                        'profile_photo' => $request->profile_photo,
                        'device_token' => $request->device_token ?? '',
                    ];

                    // Store firebase_uid if provided
                    if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                        $userData['firebase_uid'] = $request->firebase_uid;
                    }

                    $user = User::create($userData);

                    $authData = $this->createAuthToken($user, $request->device_token ?? '', 'google');

                    $isNewUser = true;
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                        $user = User::withoutGlobalScopes()
                            ->withTrashed()
                            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($request->email))])
                            ->where('role_id', 2)
                            ->first();

                        if (!$user) {
                            throw $e;
                        }
                    } else {
                        throw $e;
                    }
                }
            }
        }

        if ($user && !$isNewUser) {
            if ($user->trashed()) {
                $user->restore();
            }

            if ($request->has('password') && !empty($request->password)) {
                $user->update([
                    'password' => Hash::make($request->password),
                ]);
            }

            $updateData = [];

            if ($request->has('email') && !empty(trim($request->email))) {
                $providedEmail = trim($request->email);
                if (empty($user->email) || is_null($user->email)) {
                    $updateData['email'] = $providedEmail;
                }
            }

            if ($request->has('name') && !empty($request->name)) {
                $updateData['name'] = $request->name;
            }
            if ($request->has('device_token') && !empty($request->device_token)) {
                $updateData['device_token'] = $request->device_token;
            }
            if ($request->has('profile_photo') && !empty($request->profile_photo)) {
                $updateData['profile_photo'] = $request->profile_photo;
            }
            if ($request->has('phone') && !empty($request->phone)) {
                $updateData['phone'] = $request->phone;
            }
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $updateData['firebase_uid'] = $request->firebase_uid;
            }

            if (!empty($updateData)) {
                $user->update($updateData);
            }

            $authData = $this->createAuthToken($user, $request->device_token ?? $user->device_token ?? '', 'google');
        }

        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been blocked. Please contact support.',
            ], 422);
        }

        if (!$isNewUser) {
            $authData = $authData ?? $this->createAuthToken($user, $request->device_token ?? '', 'google');

            if ($user->status === 'under_review') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your Document is in Under Review',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            if ($user->status === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your document is rejected please upload again',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            $hasPendingDocuments = $this->driverHasDocumentsWithStatus($user, ['pending']);
            $hasRejectedDocuments = $this->driverHasDocumentsWithStatus($user, ['rejected']);

            if ($hasPendingDocuments) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your Document is in Under Review',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            if ($hasRejectedDocuments) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your document is rejected please upload again',
                    'token' => $authData['token'],
                ], 200);
            }
        }

        $authData = $authData ?? $this->createAuthToken($user, $request->device_token ?? '', 'google');

        $needsRegistration = $this->driverNeedsRegistration($user);

        if ($request->has('latitude') && $request->has('longitude')) {
            $this->updateDriverLocation($user, $request);
        }

        $driverData = $this->getDriverResponse($user);
        $driverData['is_email'] = 0;  // Social login (Google)
        $driverData['new_register'] = $isNewUser ? 1 : 0;  // 1 for new user, 0 for existing

        $isVerify = true;
        if ($user->status === 'under_review' || $user->status === 'rejected') {
            $isVerify = false;
        } else {
            $hasPendingDocuments = $this->driverHasDocumentsWithStatus($user, ['pending']);
            $hasRejectedDocuments = $this->driverHasDocumentsWithStatus($user, ['rejected']);
            if ($hasPendingDocuments || $hasRejectedDocuments) {
                $isVerify = false;
            }
        }
        $driverData['is_verify'] = $isVerify;

        $response = [
            'success' => true,
            'message' => 'Login successful',
            'token' => $authData['token'],
            'driver' => $driverData,
            'document_data' => $this->getDocumentData($user),
        ];

        if ($isNewUser && $user->status === 'incomplete') {
            $response['step'] = 0;
            $response['next_step'] = 1;
            $response['message'] = 'Account created successfully. Please complete Step 0 to continue.';
        }

        return response()->json($response);
    }

    private function handleDriverAppleLogin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'id' => ['nullable', 'string'],
                'email' => ['required_without:id', 'email'],
                'password' => ['nullable', 'string'],  // Password is optional for social login
                'name' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],
                'device_token' => ['nullable', 'string'],
                'firebase_uid' => ['nullable', 'string'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'heading' => ['nullable', 'numeric', 'between:0,360'],
                'address' => ['nullable', 'string', 'max:500'],
                'zone_id' => ['nullable', 'exists:zones,id'],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
            ], 422);
        }

        if ($request->has('id') && !empty($request->id)) {
            $user = User::where('id', $request->id)
                ->where('role_id', 2)  // Driver role
                ->first();

            if ($user) {
                $updateData = [];
                if ($request->has('email') && !empty($request->email)) {
                    $updateData['email'] = $request->email;
                }
                if ($request->has('name') && !empty($request->name)) {
                    $updateData['name'] = $request->name;
                }
                if ($request->has('device_token') && !empty($request->device_token)) {
                    $updateData['device_token'] = $request->device_token;
                }
                if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                    $updateData['firebase_uid'] = $request->firebase_uid;
                }

                if (!empty($updateData)) {
                    $user->update($updateData);
                }
            } else {
                if ($request->has('email')) {
                    $user = User::where('email', $request->email)
                        ->where('role_id', 2)  // Driver role
                        ->first();
                }
            }
        } else {
            $user = User::where('email', $request->email)
                ->where('role_id', 2)  // Driver role
                ->first();
        }

        $isNewUser = false;
        if (!$user) {
            $userData = [
                'name' => $request->name ?? null,  // Will be filled in Step 1 if not provided
                'email' => $request->email ?? null,  // Can be null if not provided
                'phone' => $request->phone,  // Optional phone for social login
                'role_id' => 2,  // Driver role
                'status' => 'incomplete',  // Mark as incomplete for Step 0 flow
                'email_verified_at' => now(),  // Apple accounts with email are pre-verified
                'device_token' => $request->device_token ?? '',
            ];

            // Store firebase_uid if provided
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $userData['firebase_uid'] = $request->firebase_uid;
            }

            $user = User::create($userData);

            $authData = $this->createAuthToken($user, $request->device_token ?? '', 'apple');

            $isNewUser = true;
        }

        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been blocked. Please contact support.',
            ], 422);
        }

        if (!$isNewUser) {
            // Update firebase_uid if provided
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $user->update(['firebase_uid' => $request->firebase_uid]);
            }

            $authData = $authData ?? $this->createAuthToken($user, $request->device_token ?? '', 'apple');

            if ($user->status === 'under_review') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your Document is in Under Review',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            if ($user->status === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your document is rejected please upload again',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            $hasPendingDocuments = $this->driverHasDocumentsWithStatus($user, ['pending']);
            $hasRejectedDocuments = $this->driverHasDocumentsWithStatus($user, ['rejected']);

            if ($hasPendingDocuments) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your Document is in Under Review',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            if ($hasRejectedDocuments) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your document is rejected please upload again',
                    'token' => $authData['token'],
                ], 200);
            }
        }

        $authData = $authData ?? $this->createAuthToken($user, $request->device_token ?? '', 'apple');

        $needsRegistration = $this->driverNeedsRegistration($user);

        if ($request->has('latitude') && $request->has('longitude')) {
            $this->updateDriverLocation($user, $request);
        }

        $driverData = $this->getDriverResponse($user);
        $driverData['is_email'] = 0;  // Social login (Apple)
        $driverData['new_register'] = $isNewUser ? 1 : 0;  // 1 for new user, 0 for existing

        $isVerify = true;
        if ($user->status === 'under_review' || $user->status === 'rejected') {
            $isVerify = false;
        } else {
            $hasPendingDocuments = $this->driverHasDocumentsWithStatus($user, ['pending']);
            $hasRejectedDocuments = $this->driverHasDocumentsWithStatus($user, ['rejected']);
            if ($hasPendingDocuments || $hasRejectedDocuments) {
                $isVerify = false;
            }
        }
        $driverData['is_verify'] = $isVerify;

        $response = [
            'success' => true,
            'message' => 'Login successful',
            'token' => $authData['token'],
            'driver' => $driverData,
            'document_data' => $this->getDocumentData($user),
        ];

        if ($isNewUser && $user->status === 'incomplete') {
            $response['step'] = 0;
            $response['next_step'] = 1;
            $response['message'] = 'Account created successfully. Please complete Step 0 to continue.';
        }

        return response()->json($response);
    }

    private function handleDriverOtherEmailLogin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
                'password' => ['nullable', 'string'],  // Password is optional for other email login
                'name' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],
                'device_token' => ['nullable', 'string'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'heading' => ['nullable', 'numeric', 'between:0,360'],
                'address' => ['nullable', 'string', 'max:500'],
                'zone_id' => ['nullable', 'exists:zones,id'],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
            ], 422);
        }

        $user = User::where('email', $request->email)
            ->where('role_id', 2)  // Driver role
            ->first();

        $isNewUser = false;
        if (!$user) {
            $userData = [
                'name' => $request->name ?? null,  // Will be filled in Step 1 if not provided
                'email' => $request->email,
                'phone' => $request->phone,  // Optional phone for other email login
                'role_id' => 2,  // Driver role
                'status' => 'incomplete',  // Mark as incomplete for Step 0 flow
                'email_verified_at' => now(),  // Other email accounts are pre-verified
                'device_token' => $request->device_token ?? '',
            ];

            // Store firebase_uid if provided
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $userData['firebase_uid'] = $request->firebase_uid;
            }

            $user = User::create($userData);

            $authData = $this->createAuthToken($user, $request->device_token ?? '', 'email');

            $isNewUser = true;
        }

        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been blocked. Please contact support.',
            ], 422);
        }

        if (!$isNewUser) {
            $authData = $authData ?? $this->createAuthToken($user, $request->device_token ?? '', 'email');

            if ($user->status === 'under_review') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your Document is in Under Review',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            if ($user->status === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your document is rejected please upload again',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            $hasPendingDocuments = $this->driverHasDocumentsWithStatus($user, ['pending']);
            $hasRejectedDocuments = $this->driverHasDocumentsWithStatus($user, ['rejected']);

            if ($hasPendingDocuments) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your Document is in Under Review',
                    'token' => $authData['token'],
                    'driver' => $this->getDriverResponse($user),
                    'document_data' => $this->getDocumentData($user),
                ], 200);
            }

            if ($hasRejectedDocuments) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your document is rejected please upload again',
                    'token' => $authData['token'],
                ], 200);
            }
        }

        $authData = $authData ?? $this->createAuthToken($user, $request->device_token ?? '', 'email');

        $needsRegistration = $this->driverNeedsRegistration($user);

        if ($request->has('latitude') && $request->has('longitude')) {
            $this->updateDriverLocation($user, $request);
        }

        $driverData = $this->getDriverResponse($user);
        $driverData['is_email'] = 0;  // Social login (Other Email)
        $driverData['new_register'] = $isNewUser ? 1 : 0;  // 1 for new user, 0 for existing

        $isVerify = true;
        if ($user->status === 'under_review' || $user->status === 'rejected') {
            $isVerify = false;
        } else {
            $hasPendingDocuments = $this->driverHasDocumentsWithStatus($user, ['pending']);
            $hasRejectedDocuments = $this->driverHasDocumentsWithStatus($user, ['rejected']);
            if ($hasPendingDocuments || $hasRejectedDocuments) {
                $isVerify = false;
            }
        }
        $driverData['is_verify'] = $isVerify;

        $response = [
            'success' => true,
            'message' => 'Login successful',
            'token' => $authData['token'],
            'driver' => $driverData,
            'document_data' => $this->getDocumentData($user),
        ];

        if ($isNewUser && $user->status === 'incomplete') {
            $response['step'] = 0;
            $response['next_step'] = 1;
            $response['message'] = 'Account created successfully. Please complete Step 0 to continue.';
        }

        return response()->json($response);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'device_token' => null,
            'last_location_at' => null,
            'bearer_token' => null,
            'token_expires_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    public function refreshToken(Request $request): JsonResponse
    {
        $user = $request->user();

        $newToken = $this->refreshBearerToken($user);

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully',
            'token' => $newToken,
            'expires_at' => $user->token_expires_at,
        ]);
    }

    public function updateRegistrationNumber(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'registration_number' => ['required', 'string', 'max:50'],
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $errorMessages[] = $message;
                }
            }
            return response()->json([
                'success' => false,
                'message' => implode(' ', $errorMessages),
            ], 422);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
                'error' => 'Invalid or missing authentication token'
            ], 401);
        }

        if ($user->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied',
                'error' => 'Only drivers can update registration number'
            ], 403);
        }

        $vehicle = $user->vehicles()->first();

        if (!$vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'Vehicle not found. Please register a vehicle first.',
            ], 404);
        }

        $driverProfile = $user->driverProfile;
        if (!$driverProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Driver profile not found.',
            ], 404);
        }

        $driverMetaData = $driverProfile->meta_data ?? [];
        $vehicleRegistrationStatus = $driverMetaData['vehicle_registration_status'] ?? 'pending';
        $isVehicleRejected = $vehicle->status === 'rejected' || $vehicleRegistrationStatus === 'rejected';

        if (!$isVehicleRejected) {
            return response()->json([
                'success' => false,
                'message' => 'Registration number can only be updated when it has been rejected by admin.',
            ], 400);
        }

        $existingVehicle = Vehicle::where('registration_number', $request->registration_number)
            ->where('id', '!=', $vehicle->id)
            ->first();

        if ($existingVehicle) {
            return response()->json([
                'success' => false,
                'message' => 'This vehicle registration number is already registered. Please use a different registration number.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $vehicle->update([
                'registration_number' => $request->registration_number,
                'status' => 'pending',
                'rejection_reason' => null,
            ]);

            $vehicle->refresh();

            $metaData = $driverProfile->meta_data ?? [];
            $metaData['vehicle_registration_status'] = 'pending';
            $metaData['vehicle_registration_approved'] = false;
            $metaData['vehicle_registration_rejection_reason'] = null;

            $driverProfile->update([
                'meta_data' => $metaData,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Registration number updated successfully. Please wait for admin approval.',
                'data' => [
                    'registration_number' => $vehicle->registration_number,
                    'status' => $vehicle->status,
                    'registration_status' => 'pending',
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update registration number: ' . $e->getMessage(),
            ], 500);
        }
    }

    protected function validateDriverRegistration(array $data): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'phone' => [
                'required',
                'string',
                'regex:/^[0-9]{10}$/',
                function ($attribute, $value, $fail) {
                    $existingDriver = User::where('phone', $value)
                        ->where('role_id', 2)
                        ->first();

                    if ($existingDriver) {
                        $fail('This phone number is already registered as a driver.');
                    }
                }
            ],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'date_of_birth' => ['required', 'date', 'before:' . now()->subYears(18)->format('Y-m-d')],
            'profile_photo' => ['nullable', 'file', 'image', 'max:5120'],  // 5MB max
            'city_id' => ['nullable', 'exists:cities,id'],
            'address' => ['nullable', 'string', 'min:10', 'max:500'],
            'license_number' => ['nullable', 'string', 'unique:driver_profiles,license_number'],
            'identity_number' => ['nullable', 'string', 'unique:driver_profiles,identity_number'],
            'identity_type' => ['nullable', 'in:aadhar,pan,voter_id,national_id'],
            'ride_type_id' => ['nullable', 'exists:ride_types,id'],
            'registration_number' => ['nullable', 'string', 'max:50', 'unique:vehicles,registration_number'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:' . date('Y')],
            'government_id_front' => ['nullable', 'file', 'image', 'max:5120'],
            'government_id_back' => ['nullable', 'file', 'image', 'max:5120'],
            'live_selfie' => ['nullable', 'file', 'image', 'max:5120'],
            'vehicle_documents' => ['nullable', 'array'],
            'vehicle_documents.*.document_type' => ['nullable_with:vehicle_documents', 'exists:document_lists,id'],
            'vehicle_documents.*.document_file' => ['nullable_with:vehicle_documents', 'file', 'max:5120'],
            'device_token' => ['nullable', 'string'],
            'referral_code' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if (!empty($value)) {
                        $exists = \App\Models\User::where('referral_code', $value)
                            ->where('role_id', 2)
                            ->exists();
                        if (!$exists) {
                            $fail('The referral code is invalid. Only driver referral codes can be used.');
                        }
                    }
                }
            ],
        ];

        $messages = [
            'phone.regex' => 'Please enter a valid 10-digit phone number.',
            'email.unique' => 'This email address is already registered.',
            'license_number.unique' => 'This license number is already registered.',
            'identity_number.unique' => 'This identity number is already registered.',
            'identity_type.in' => 'The selected identity type is invalid.',
            'date_of_birth.before' => 'You must be at least 18 years old to register as a driver.',
            'registration_number.unique' => 'This vehicle registration number is already registered.',
            'ride_type_id.exists' => 'The selected ride type is invalid.',
            'government_id_front.required' => 'Government ID front photo is required.',
            'government_id_back.required' => 'Government ID back photo is required.',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }

    protected function validatePhoneUniqueByRole($attribute, $value, $fail, $roleId)
    {
        $existingUser = User::where('phone', $value)
            ->where('role_id', $roleId)
            ->first();

        if ($existingUser) {
            $roleName = $roleId == 1 ? 'user' : 'driver';
            $fail("This phone number is already registered as a {$roleName}.");
        }
    }

    protected function getDriverResponse(User $user): array
    {
        $vehicle = $user->vehicles()->first();

        return [
            'id' => (string) $user->id,
            'name' => $user->name ?? '',
            'phone' => $user->phone ?? '',
            'country_code' => $user->country_code ?? '',
            'email' => $user->email ?? '',
            'gender' => $user->gender ?? '',
            'role' => (string) $user->role_id,
            'profile_photo' => $this->getProfilePhotoUrl($user->profile_photo),
            'status' => $user->status ?? '',
            'referral_code' => $user->referral_code ?? '',
            'wallet_balance' => (string) ($user->wallet?->balance ?? 0),
            'is_verified' => (string) ($this->checkDriverDocumentVerification($user) ? 1 : 0),
            'city' => $user->driverProfile?->city?->name ?? '',
            'rating' => (string) ($user->driverProfile?->rating ?? 0),
            'total_trips' => (string) ($user->driverProfile?->total_trips ?? 0),
            'is_online' => (string) ($user->is_online ?? false),
            'is_register' => (string) ($user->is_register ?? 0),
            'step_0' => (string) ($user->step_0 ?? 0),
            'step_1' => (string) ($user->step_1 ?? 0),
            'step_2' => (string) ($user->step_2 ?? 0),
            'step_3' => (string) ($user->step_3 ?? 0),
            'vehicle' => $vehicle ? [
                'id' => (string) $vehicle->id,
                'brand' => $vehicle->brand ?? '',
                'model' => $vehicle->model ?? '',
                'year' => (string) ($vehicle->year ?? ''),
                'registration_number' => $vehicle->registration_number ?? '',
                'license_plate' => $vehicle->license_plate ?? '',
                'color' => $vehicle->color ?? '',
                'ride_type' => $vehicle->rideType?->name ?? '',
            ] : [
                'id' => '',
                'brand' => '',
                'model' => '',
                'year' => '',
                'registration_number' => '',
                'license_plate' => '',
                'color' => '',
                'ride_type' => '',
            ],
        ];
    }

    protected function checkDriverDocumentVerification(User $user): bool
    {
        $governmentIdFront = $user->documents()->where('type', 'government_id_front')->first();
        $governmentIdBack = $user->documents()->where('type', 'government_id_back')->first();
        $selfi = $user->documents()->whereIn('type', ['selfi', 'live_selfie', 'selfie'])->first();

        if (!$governmentIdFront || $governmentIdFront->status !== 'approved') {
            return false;
        }
        if (!$governmentIdBack || $governmentIdBack->status !== 'approved') {
            return false;
        }
        if (!$selfi || $selfi->status !== 'approved') {
            return false;
        }

        $requiredDocuments = \App\Models\DocumentList::where('type', 'driver')
            ->where('is_required', true)
            ->where('is_active', true)
            ->where(function ($query) {
                $query
                    ->where('is_new', false)
                    ->orWhereNull('is_new');
            })
            ->get();

        foreach ($requiredDocuments as $requiredDoc) {
            $fieldName = $this->getDocumentFieldName($requiredDoc->name);

            if (in_array($fieldName, ['government_id_front', 'government_id_back', 'selfi', 'live_selfie', 'selfie'])) {
                continue;
            }

            $frontDoc = $user->documents()->where('type', $fieldName . '_front')->first();
            $backDoc = $user->documents()->where('type', $fieldName . '_back')->first();
            $singleDoc = $user->documents()->where('type', $fieldName)->first();

            $hasApproved = ($frontDoc && $frontDoc->status === 'approved') ||
                ($backDoc && $backDoc->status === 'approved') ||
                ($singleDoc && $singleDoc->status === 'approved');

            if (!$hasApproved) {
                return false;
            }
        }

        if (!$user->hasApprovedVehicleRegistration()) {
            return false;
        }

        if ($this->hasExpiredDocumentDeadlines($user)) {
            return false;
        }

        return true;
    }

    protected function hasExpiredDocumentDeadlines(User $user): bool
    {
        $expiredNotifications = \App\Models\DriverDocumentNotification::where('driver_id', $user->id)
            ->where('deadline_at', '<=', now())
            ->where('is_uploaded', false)
            ->whereHas('documentList', function ($query) {
                $query
                    ->where('is_new', true)
                    ->where('is_active', true);
            })
            ->with('documentList')
            ->get();

        foreach ($expiredNotifications as $notification) {
            $hasDocument = $this->checkDriverHasDocumentForNotification($user, $notification->documentList);

            if (!$hasDocument) {
                return true;
            }
        }

        return false;
    }

    protected function checkDriverHasDocumentForNotification(User $user, $documentList): bool
    {
        $fieldName = $this->getDocumentFieldName($documentList->name);

        if ($documentList->type === 'driver') {
            $hasFront = $user->documents()->where('type', $fieldName . '_front')->exists();
            $hasBack = $user->documents()->where('type', $fieldName . '_back')->exists();
            $hasSingle = $user->documents()->where('type', $fieldName)->exists();

            return $hasFront || $hasBack || $hasSingle;
        } else {
            foreach ($user->vehicles as $vehicle) {
                if ($vehicle->documents()->where('type', $fieldName)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function driverHasDocumentsWithStatus(User $user, array $statuses): bool
    {
        $statuses = array_filter($statuses);
        if (empty($statuses)) {
            return false;
        }

        $requiredTypeMap = DocumentList::getRequiredTypeMap();
        $requiredDriverTypes = $requiredTypeMap['driver'] ?? [];
        $requiredVehicleTypes = $requiredTypeMap['vehicle'] ?? [];

        $driverDocumentsQuery = $user
            ->documents()
            ->whereIn('status', $statuses);

        if (!empty($requiredDriverTypes)) {
            $driverDocumentsQuery->whereIn('type', $requiredDriverTypes);
        }

        if ($driverDocumentsQuery->exists()) {
            return true;
        }

        return $user
            ->vehicles()
            ->whereHas('documents', function ($query) use ($statuses, $requiredVehicleTypes) {
                $query->whereIn('status', $statuses);

                if (!empty($requiredVehicleTypes)) {
                    $query->whereIn('type', $requiredVehicleTypes);
                }
            })
            ->exists();
    }

    protected function getDocumentData(User $user): array
    {
        $driverDocuments = $user->documents()->get()->map(function ($document) {
            $documentType = $document->type ?? '';
            if ($documentType === 'selfie') {
                $documentType = 'selfi';
            }

            return [
                'id' => (string) $document->id,
                'type' => $documentType,
                'status' => $document->status ?? '',
                'isverify' => $document->status === 'pending' || $document->status === 'rejected' ? false : true,
                'front_file_url' => $document->front_file_url ?? '',
                'back_file_url' => $document->back_file_url ?? '',
                'uploaded_at' => $document->created_at?->toISOString() ?? '',
                'updated_at' => $document->updated_at?->toISOString() ?? '',
            ];
        });

        $vehicleDocuments = collect();
        if ($user->vehicles()->exists()) {
            $vehicle = $user->vehicles()->first();
            $vehicleDocuments = $vehicle->documents()->get()->map(function ($document) {
                return [
                    'id' => (string) $document->id,
                    'type' => $document->type ?? '',
                    'status' => $document->status ?? '',
                    'isverify' => $document->status === 'pending' || $document->status === 'rejected' ? false : true,
                    'front_file_url' => $document->front_file_url ?? '',
                    'back_file_url' => $document->back_file_url ?? '',
                    'uploaded_at' => $document->created_at?->toISOString() ?? '',
                    'updated_at' => $document->updated_at?->toISOString() ?? '',
                ];
            });
        }

        return [
            'driver_documents' => $driverDocuments,
            'vehicle_documents' => $vehicleDocuments,
        ];
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

    protected function getDriverStatistics(User $user): array
    {
        $totalOnlineHours = \App\Models\DriverAttendance::getTotalOnlineHours($user->id);
        $currentSession = \App\Models\DriverAttendance::getCurrentOnlineSession($user->id);

        if ($user->is_online && $currentSession) {
            $currentSessionSeconds = $currentSession->calculateOnlineTime();
            $currentSessionHours = $currentSessionSeconds > 0
                ? round($currentSessionSeconds / 3600, 4)
                : 0;
            $totalOnlineHours += $currentSessionHours;
        }

        $allBookings = $user->bookingsAsDriver();
        $totalTrips = $allBookings->count();
        $completedTrips = $allBookings->where('status', 'completed')->count();
        $completionRate = $totalTrips > 0 ? round(($completedTrips / $totalTrips) * 100, 1) : 0;

        $avgRating = (float) ($allBookings->where('status', 'completed')->avg('driver_rating') ?? 0);

        $recentTrip = $allBookings
            ->where('status', 'completed')
            ->with(['user', 'pickupZone', 'dropoffZone'])
            ->latest('completed_at')
            ->first();

        $recentTripData = '';
        if ($recentTrip) {
            $recentTripData = [
                'id' => (string) $recentTrip->id,
                'booking_code' => $recentTrip->booking_code ?? '',
                'pickup_address' => $recentTrip->pickup_address ?? '',
                'dropoff_address' => $recentTrip->dropoff_address ?? '',
                'pickup_zone_name' => $recentTrip->pickupZone?->name ?? '',
                'total_amount' => (string) ($recentTrip->total_amount ?? '0'),
                'payment_method' => $recentTrip->payment_method ?? '',
                'completed_at' => $recentTrip->completed_at?->format('Y-m-d H:i:s') ?? '',
                'duration' => (string) ($recentTrip->actual_duration ?? '0'),
                'distance' => (string) ($recentTrip->actual_distance ?? '0'),
                'rating' => (string) ($recentTrip->user_rating ?? '5.0'),
            ];
        } else {
            $recentTripData = null;
        }

        $hotspotData = $this->getHotspotAreaData($user);

        return [
            'total_time_online' => (string) round($totalOnlineHours, 2),
            'total_ride' => (string) $totalTrips,
            'total_ride_completed' => (string) $completedTrips,
            'completion_rate' => (string) $completionRate,
            'avg_rating' => (string) round($avgRating, 1),
            'recent_trip' => $recentTripData,
            'hotspot_area' => $hotspotData,
        ];
    }

    protected function getHotspotAreaData(User $user): array
    {
        $mostActiveZone = $user
            ->bookingsAsDriver()
            ->where('status', 'completed')
            ->whereNotNull('pickup_zone_id')
            ->with('pickupZone')
            ->get()
            ->groupBy('pickup_zone_id')
            ->map(function ($bookings) {
                return [
                    'zone' => $bookings->first()->pickupZone,
                    'count' => $bookings->count(),
                    'peak_hours' => $this->calculatePeakHours($bookings),
                ];
            })
            ->sortByDesc('count')
            ->first();

        if ($mostActiveZone && $mostActiveZone['zone']) {
            return [
                'zone_name' => $mostActiveZone['zone']->name ?? 'Unknown Area',
                'trip_count' => (string) $mostActiveZone['count'],
                'peak_hours' => $mostActiveZone['peak_hours'],
                'surge_multiplier' => (string) ($mostActiveZone['zone']->surge_multiplier ?? '1.0'),
                'is_surge_active' => $mostActiveZone['zone']->isSurgeActive() ? '1' : '0',
            ];
        }

        return [
            'zone_name' => 'No hotspot data',
            'trip_count' => '0',
            'peak_hours' => 'No data available',
            'surge_multiplier' => '1.0',
            'is_surge_active' => '0',
        ];
    }

    protected function calculatePeakHours($bookings): string
    {
        $hourCounts = $bookings->groupBy(function ($booking) {
            return $booking->completed_at?->format('H') ?? '0';
        })->map->count();

        if ($hourCounts->isEmpty()) {
            return 'No data available';
        }

        $peakHour = $hourCounts->sortDesc()->keys()->first();
        $peakHourInt = (int) $peakHour;

        $startHour = max(0, $peakHourInt - 1);
        $endHour = min(23, $peakHourInt + 1);

        return sprintf('%02d:00 - %02d:00', $startHour, $endHour);
    }

    protected function isDriverFullyVerified(User $user): bool
    {
        $hasRejectedDriverDocs = $user->documents()->where('status', 'rejected')->exists();
        if ($hasRejectedDriverDocs) {
            return false;
        }

        $vehicle = $user->vehicles()->first();
        if ($vehicle) {
            $hasRejectedVehicleDocs = $vehicle->documents()->where('status', 'rejected')->exists();
            if ($hasRejectedVehicleDocs) {
                return false;
            }
        }

        $requiredDriverDocs = \App\Models\DocumentList::where('type', 'driver')
            ->where('is_required', true)
            ->where('is_active', true)
            ->where(function ($query) {
                $query
                    ->where('is_new', false)
                    ->orWhereNull('is_new');
            })
            ->get();

        $requiredVehicleDocs = \App\Models\DocumentList::where('type', 'vehicle')
            ->where('is_required', true)
            ->where('is_active', true)
            ->where(function ($query) {
                $query
                    ->where('is_new', false)
                    ->orWhereNull('is_new');
            })
            ->get();

        foreach ($requiredDriverDocs as $docType) {
            $docTypeName = strtolower(str_replace(' ', '_', $docType->name));

            $found = false;

            if ($docTypeName === 'government_id') {
                $frontDoc = $user->documents()->where('type', 'government_id_front')->first();
                $backDoc = $user->documents()->where('type', 'government_id_back')->first();

                if (
                    $frontDoc &&
                    $backDoc &&
                    $frontDoc->status === 'approved' &&
                    $backDoc->status === 'approved'
                ) {
                    $found = true;
                }
            } else {
                $document = $user->documents()->where('type', $docTypeName)->first();
                if ($document && $document->status === 'approved') {
                    $found = true;
                }
            }

            if (!$found) {
                return false;
            }
        }

        $vehicle = $user->vehicles()->first();
        if ($vehicle) {
            foreach ($requiredVehicleDocs as $docType) {
                $docTypeName = strtolower(str_replace(' ', '_', $docType->name));
                $document = $vehicle->documents()->where('type', $docTypeName)->first();

                if (!$document || $document->status !== 'approved') {
                    return false;
                }
            }
        } else {
            return false;
        }

        if ($this->hasExpiredDocumentDeadlines($user)) {
            return false;
        }

        return true;
    }

    protected function updateDriverLocation(User $user, Request $request): void
    {
        // 🚫 Autogenerated driver check — HARD STOP
        // Autogenerated drivers have email pattern: {cityname}driver@etaxi.com
        if ($this->isAutogeneratedDriver($user)) {
            
            return;
        }

        $user->update([
            'last_latitude' => $request->latitude,
            'last_longitude' => $request->longitude,
            'last_location_at' => now(),
        ]);

        $address = $request->address;
        if (empty($address)) {
            $geocodingService = app(GeocodingService::class);
            $address = $geocodingService->getAddressFromCoordinates($request->latitude, $request->longitude);
        }

        try {
            $locationData = [
                'driver_id' => $user->id,
                'zone_id' => $request->zone_id ?? 1,  // Default zone
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'address' => $address ?? '',
                'heading' => $request->heading ?? null,
                'speed' => $request->speed ?? null,
                'accuracy' => $request->accuracy ?? null,
                'battery_level' => $request->battery_level ?? null,
                'is_charging' => $request->is_charging ?? false,
                'is_active' => true,
                'recorded_at' => now(),
            ];

            $existingRecord = DB::table('driver_locations')
                ->where('driver_id', $user->id)
                ->where('is_active', true)
                ->first();

            if ($existingRecord) {
                DB::table('driver_locations')
                    ->where('id', $existingRecord->id)
                    ->update([
                        'zone_id' => $locationData['zone_id'],
                        'latitude' => $locationData['latitude'],
                        'longitude' => $locationData['longitude'],
                        'address' => $locationData['address'],
                        'heading' => $locationData['heading'],
                        'speed' => $locationData['speed'],
                        'accuracy' => $locationData['accuracy'],
                        'battery_level' => $locationData['battery_level'],
                        'is_charging' => $locationData['is_charging'],
                        'recorded_at' => $locationData['recorded_at'],
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('driver_locations')->insert($locationData);
            }
        } catch (\Exception $e) {
            // Error saving driver location
        }
    }

    public function getRequiredDocuments(): JsonResponse
    {
        $documents = \App\Models\DocumentList::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $documentsList = [];
        foreach ($documents as $document) {
            $key = $this->generateDocumentKey($document->name);
            if ($document->type === 'driver') {
                $key = $key . '_d';
            }

            $documentsList[] = [
                'id' => $document->id,
                'name' => $document->name,
                'key' => $key,
                'description' => $document->description ?? '',
                'type' => $document->type,
                'is_required' => $document->is_required == 1,
                'sort_order' => (string)$document->sort_order,
                'max_size' => '5MB',
                'accepted_formats' => ['jpg', 'jpeg', 'png', 'pdf'],
                'instructions' => $document->description ?? 'Please upload a clear and valid document.',
            ];
        }

        $requiredCount = collect($documentsList)->where('is_required', true)->count();

        return response()->json([
            'success' => true,
            'message' => 'Documents list retrieved successfully',
            'data' => [
                'total_documents' => count($documentsList),
                'total_required' => $requiredCount,
                'documents' => $documentsList,
                'general_instructions' => [
                    'All documents must be clear and legible',
                    'File size should not exceed the specified limit',
                    'Both front and back sides are required where applicable',
                    'Documents should be recent and valid',
                    'Expired documents will not be accepted',
                ],
                'upload_guidelines' => [
                    'Supported formats: JPG, JPEG, PNG, PDF',
                    'Maximum file size: 5MB',
                    'Ensure good lighting and clear visibility',
                    'Avoid blurry or incomplete images',
                    'Do not upload screenshots or edited documents',
                ],
            ],
        ]);
    }

    private function generateDocumentKey(string $name): string
    {
        $key = strtolower(str_replace(' ', '_', trim($name)));

        $key = str_replace(['_certificate', '_cert'], '', $key);

        $key = preg_replace('/_{2,}/', '_', $key);
        $key = trim($key, '_');

        return $key ?: strtolower(str_replace(' ', '_', $name));
    }

    public function getVehicleDocuments(): JsonResponse
    {
        $vehicleDocuments = \App\Models\DocumentList::where('type', 'vehicle')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'description', 'is_required', 'sort_order']);

        return response()->json([
            'success' => true,
            'message' => 'Vehicle documents retrieved successfully.',
            'vehicle_documents' => $vehicleDocuments,
        ]);
    }

    public function getDriverDocuments(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user || !$user->isDriver()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver not found or invalid user type.',
                ], 404);
            }

            $driverDocuments = $user->documents()->get()->map(function ($document) {
                $documentType = $document->type ?? '';
                if ($documentType === 'selfie') {
                    $documentType = 'selfi';
                }

                return [
                    'id' => $document->id,
                    'type' => $documentType,
                    'status' => $document->status,
                    'front_file_url' => $document->front_file_url,
                    'back_file_url' => $document->back_file_url,
                    'uploaded_at' => $document->created_at?->toISOString(),
                    'updated_at' => $document->updated_at?->toISOString(),
                ];
            });

            $vehicleDocuments = collect();
            if ($user->vehicles()->exists()) {
                $vehicle = $user->vehicles()->first();
                $vehicleDocuments = $vehicle->documents()->get()->map(function ($document) {
                    return [
                        'id' => $document->id,
                        'type' => $document->type,
                        'status' => $document->status,
                        'front_file_url' => $document->front_file_url,
                        'back_file_url' => $document->back_file_url,
                        'uploaded_at' => $document->created_at?->toISOString(),
                        'updated_at' => $document->updated_at?->toISOString(),
                    ];
                });
            }

            return response()->json([
                'success' => true,
                'message' => 'Documents retrieved successfully.',
                'driver_documents' => $driverDocuments,
                'vehicle_documents' => $vehicleDocuments,
                'total_documents' => $driverDocuments->count() + $vehicleDocuments->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve documents.',
            ], 500);
        }
    }

    protected function getUploadedDocumentsInfo(User $user, ?Vehicle $vehicle): array
    {
        $documentsInfo = [
            'driver_documents' => [],
            'vehicle_documents' => [],
            'total_uploaded' => 0,
        ];

        $driverDocuments = $user->documents()->where(function ($query) {
            $query->whereNotNull('file_front')->orWhereNotNull('file_back');
        })->get();

        foreach ($driverDocuments as $document) {
            $fileUrl = null;
            if ($document->file_front) {
                $fileUrl = asset('storage/' . $document->file_front);
            } elseif ($document->file_back) {
                $fileUrl = asset('storage/' . $document->file_back);
            }

            $documentType = $document->type ?? '';
            if ($documentType === 'selfie') {
                $documentType = 'selfi';
            }

            $documentsInfo['driver_documents'][] = [
                'type' => $documentType,
                'status' => $document->status,
                'file_url' => $fileUrl ?: '',
                'file_front_url' => $document->file_front ? asset('storage/' . $document->file_front) : '',
                'file_back_url' => $document->file_back ? asset('storage/' . $document->file_back) : '',
                'uploaded_at' => $document->created_at?->format('Y-m-d H:i:s'),
            ];
        }

        if ($vehicle) {
            $vehicleDocuments = $vehicle->documents()->where(function ($query) {
                $query->whereNotNull('file_front')->orWhereNotNull('file_back');
            })->get();

            foreach ($vehicleDocuments as $document) {
                $fileUrl = null;
                if ($document->file_front) {
                    $fileUrl = asset('storage/' . $document->file_front);
                } elseif ($document->file_back) {
                    $fileUrl = asset('storage/' . $document->file_back);
                }

                $documentsInfo['vehicle_documents'][] = [
                    'type' => $document->type,
                    'status' => $document->status,
                    'file_url' => $fileUrl ?: '',
                    'file_front_url' => $document->file_front ? asset('storage/' . $document->file_front) : '',
                    'file_back_url' => $document->file_back ? asset('storage/' . $document->file_back) : '',
                    'uploaded_at' => $document->created_at?->format('Y-m-d H:i:s'),
                ];
            }
        }

        $documentsInfo['total_uploaded'] = count($documentsInfo['driver_documents']) + count($documentsInfo['vehicle_documents']);

        return $documentsInfo;
    }

    protected function getAuthenticatedUser(Request $request): User
    {
        $user = $request->user();

        if ($user) {
            return $user;
        }

        $token = $request->bearerToken();
        if ($token) {


            // Normalize token (remove Bearer_ prefix if present for comparison)
            $normalizedToken = str_replace('Bearer_', '', $token);

            // Try to extract driver ID from token
            $driverId = null;
            if (strpos($normalizedToken, '_') !== false) {
                $parts = explode('_', $normalizedToken, 2);
                if (count($parts) === 2 && is_numeric(hexdec($parts[0]))) {
                    $driverId = (int) hexdec($parts[0]);

                }
            }

            // Comprehensive token lookup (same as BearerTokenAuth middleware)
            $users = User::where(function ($query) use ($normalizedToken, $token) {
                $query->where('bearer_token', $normalizedToken)
                    ->orWhere('bearer_token', 'Bearer_' . $normalizedToken)
                    ->orWhere('bearer_token', $token)
                    ->orWhere('bearer_token', 'Bearer_' . str_replace('Bearer_', '', $token));
            })
                ->whereNotNull('bearer_token')
                ->where(function ($query) {
                    // Allow tokens that never expire (null) or have valid future expiration
                    $query->whereNull('token_expires_at')
                        ->orWhere('token_expires_at', '>', now());
                })
                ->where('role_id', 2)
                ->get();

            // Find exact match
            $user = null;
            foreach ($users as $candidateUser) {
                $userToken = $candidateUser->bearer_token;
                $normalizedUserToken = str_replace('Bearer_', '', $userToken);

                if (
                    $userToken === $token ||
                    $userToken === 'Bearer_' . $normalizedToken ||
                    $normalizedUserToken === $normalizedToken ||
                    $normalizedUserToken === str_replace('Bearer_', '', $token)
                ) {
                    $user = $candidateUser;
                    break;
                }
            }

            // Fallback: if driver ID extracted, try direct lookup
            if (!$user && $driverId) {

                $user = User::where('id', $driverId)
                    ->where('role_id', 2)
                    ->where(function ($query) {
                        $query->whereNull('token_expires_at')
                            ->orWhere('token_expires_at', '>', now());
                    })
                    ->first();

                if ($user) {

                }
            }

            if ($user) {

                return $user;
            } else {
            }
        }

        $phone = $request->input('phone');
        if ($phone) {

            $user = User::where('phone', $phone)
                ->where('role_id', 2)
                ->first();

            if ($user) {

                return $user;
            }
        }

        throw new \Exception('User not found. Please provide a valid Bearer token or phone number to identify the user.');
    }

    protected function validateStep0(array $data): array
    {
        $rules = [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];

        $validatedData = Validator::make($data, $rules)->validate();

        $city = $this->cityService->getNearestCity(
            $validatedData['latitude'],
            $validatedData['longitude'],
            50  // Max distance in km
        );

        if (!$city) {
            throw ValidationException::withMessages([
                'location' => ['No service available in this area. Please try a different location.'],
            ]);
        }

        $validatedData['city_id'] = $city->id;
        $validatedData['city_name'] = $city->name;

        return $validatedData;
    }

    protected function validateStep1(array $data, Request $request = null): array
    {
        $rules = [
            'name' => ['nullable', 'string', 'max:255', 'min:2'],
            'phone' => [
                'nullable',
                'string',
                'regex:/^[0-9]{10}$/',
                function ($attribute, $value, $fail) use ($request) {
                    if ($value) {
                        $authenticatedUser = null;
                        if ($request) {
                            $token = $request->bearerToken();
                            if ($token) {
                                $authenticatedUser = User::where(function ($query) use ($token) {
                                    $query
                                        ->where('bearer_token', $token)
                                        ->orWhere('bearer_token', 'Bearer_' . $token);
                                })
                                    ->where('token_expires_at', '>', now())
                                    ->where('role_id', 2)
                                    ->first();
                            }
                        }

                        if ($authenticatedUser && $authenticatedUser->phone === $value) {
                            return;  // Allow - same user updating their own phone
                        }

                        $existingDriver = User::where('phone', $value)
                            ->where('role_id', 2)
                            ->where('is_register', 1)
                            ->first();
                        
                        if ($existingDriver) {
                            $fail('This phone number is already registered as a driver.');
                        }
                    }
                }
            ],
            'email' => [
                'nullable',
                function ($attribute, $value, $fail) use ($request) {
                    // Trim email value
                    $value = trim($value);

                    // Only validate if email is provided and not empty
                    if (empty($value)) {

                        return; // Skip validation if email is not provided
                    }

                    // Validate email format
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {

                        $fail('The email must be a valid email address.');
                        return;
                    }

                    // Check max length
                    if (strlen($value) > 255) {
                        $fail('The email may not be greater than 255 characters.');
                        return;
                    }

                    // Get authenticated user from bearer token (using same logic as getAuthenticatedUser)
                    $authenticatedUser = null;
                    $token = null;

                    // Try request->user() first (if middleware sets it)
                    if ($request) {
                        try {
                            $authenticatedUser = $request->user();
                            if ($authenticatedUser && $authenticatedUser->role_id == 2) {
                                // User is a driver
                            } else {
                                $authenticatedUser = null;
                            }
                        } catch (\Exception $e) {
                            // request->user() might not be available in validation
                            $authenticatedUser = null;
                        }
                    }

                    // If not found, try bearer token (using same logic as BearerTokenAuth middleware)
                    if (!$authenticatedUser && $request) {
                        $token = $request->bearerToken();

                        if ($token) {
                            // Normalize token (remove Bearer_ prefix if present for comparison)
                            $normalizedToken = str_replace('Bearer_', '', $token);

                            // Try to extract driver ID from token
                            $driverId = null;
                            if (strpos($normalizedToken, '_') !== false) {
                                $parts = explode('_', $normalizedToken, 2);
                                if (count($parts) === 2 && is_numeric(hexdec($parts[0]))) {
                                    $driverId = (int) hexdec($parts[0]);
                                }
                            }


                            // Comprehensive token lookup (same as BearerTokenAuth middleware)
                            $users = User::where(function ($query) use ($normalizedToken, $token) {
                                $query->where('bearer_token', $normalizedToken)
                                    ->orWhere('bearer_token', 'Bearer_' . $normalizedToken)
                                    ->orWhere('bearer_token', $token)
                                    ->orWhere('bearer_token', 'Bearer_' . str_replace('Bearer_', '', $token));
                            })
                                ->whereNotNull('bearer_token')
                                ->where(function ($query) {
                                    $query->whereNull('token_expires_at')
                                        ->orWhere('token_expires_at', '>', now());
                                })
                                ->where('role_id', 2)
                                ->get();

                            // Find exact match
                            foreach ($users as $candidateUser) {
                                $userToken = $candidateUser->bearer_token;
                                $normalizedUserToken = str_replace('Bearer_', '', $userToken);

                                if (
                                    $userToken === $token ||
                                    $userToken === 'Bearer_' . $normalizedToken ||
                                    $normalizedUserToken === $normalizedToken ||
                                    $normalizedUserToken === str_replace('Bearer_', '', $token)
                                ) {
                                    $authenticatedUser = $candidateUser;
                                    break;
                                }
                            }

                            // Fallback: if driver ID extracted, try direct lookup
                            if (!$authenticatedUser && $driverId) {
                                $authenticatedUser = User::where('id', $driverId)
                                    ->where('role_id', 2)
                                    ->where(function ($query) {
                                        $query->whereNull('token_expires_at')
                                            ->orWhere('token_expires_at', '>', now());
                                    })
                                    ->first();

                                if ($authenticatedUser) {

                                }
                            }

                        }
                    }

                    // Trim request email for comparison (define outside if block)
                    $requestEmail = trim($value);

                    // If bearer token user email matches request email, allow it (no error)
                    if ($authenticatedUser) {
                        $dbEmail = trim($authenticatedUser->email ?? '');


                        if ($dbEmail === $requestEmail) {

                            return; // Email matches - allow it, no error
                        }
                    } else {

                    }

                    // Check if email belongs to any other driver (excluding authenticated user)
                    $existingDriver = User::where('email', trim($value))
                        ->where('role_id', 2)
                        ->when($authenticatedUser, function ($query) use ($authenticatedUser) {
                            $query->where('id', '!=', $authenticatedUser->id);
                        })
                        ->first();

                    

                    if ($existingDriver) {
                        
                        $fail('This email address is already registered as a driver.');
                    } else {

                    }
                }
            ],
            'date_of_birth' => ['nullable', 'date', 'before:' . now()->subYears(18)->format('Y-m-d')],
            'referral_code' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if (!empty($value)) {
                        $exists = \App\Models\User::where('referral_code', $value)
                            ->where('role_id', 2)
                            ->exists();
                        if (!$exists) {
                            $fail('The referral code is invalid. Only driver referral codes can be used.');
                        }
                    }
                }
            ],
            'device_token' => ['nullable', 'string'],
            'driver_profile_image' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],  // 5MB max
        ];

        return Validator::make($data, $rules)->validate();
    }

    protected function validateStep2(array $data, Request $request): array
    {
        $user = $this->getAuthenticatedUser($request);
        $vehicle = $user->vehicles()->first();

        $registrationNumberRule = ['nullable', 'string', 'max:50'];
        if ($vehicle) {
            $registrationNumberRule[] = 'unique:vehicles,registration_number,' . $vehicle->id;
        } else {
            $registrationNumberRule[] = 'unique:vehicles,registration_number';
        }

        $rules = [
            'phone' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],  // Optional for user identification
            'ride_type_id' => ['nullable', 'exists:ride_types,id'],
            'registration_number' => $registrationNumberRule,
        ];

        return Validator::make($data, $rules)->validate();
    }

    protected function validateStep3(array $data): array
    {
        $documentLists = \App\Models\DocumentList::where('is_active', true)->get();

        $rules = [
            'phone' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],  // Optional for user identification
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:' . date('Y')],
        ];

        $hasSelfieInList = false;
        foreach ($documentLists as $documentList) {
            $fieldName = $this->getDocumentFieldName($documentList->name);

            if ($documentList->type === 'driver') {
                $rules[$fieldName . '_front'] = ['nullable', 'file', 'image', 'max:5120'];
                $rules[$fieldName . '_back'] = ['nullable', 'file', 'image', 'max:5120'];
                $rules[$fieldName] = ['nullable', 'file', 'image', 'max:5120'];
                // Add validation for _d suffix variants
                $rules[$fieldName . '_d'] = ['nullable', 'file', 'image', 'max:5120'];
                if ($fieldName === 'live_selfie' || $fieldName === 'selfie') {
                    $rules['selfi'] = ['nullable', 'file', 'image', 'max:5120'];
                    $hasSelfieInList = true;
                }
            } else {
                $rules[$fieldName] = ['nullable', 'file', 'image', 'max:5120'];
            }
        }

        if (!$hasSelfieInList) {
            $rules['selfi'] = ['nullable', 'file', 'image', 'max:5120'];
        }

        // Add validation for common driver document fields with _d suffix
        $commonDriverDocumentFields = [
            'driver_license_d',
            'insurance_d',
            'id_proof',
            'police_verification',
            'address_proof_d',
            'background_check_d',
        ];

        foreach ($commonDriverDocumentFields as $field) {
            $rules[$field] = ['nullable', 'file', 'image', 'max:5120'];
        }

        $rules = array_merge($rules, [
            'vehicle_documents_type' => ['nullable', 'array'],
            'vehicle_documents_type.*' => ['nullable', 'exists:document_lists,id'],
            'vehicle_documents_file' => ['nullable', 'array'],
            'vehicle_documents_file.*' => ['nullable', 'file', 'max:5120'],
            'vehicle_documents' => ['nullable', 'array'],
            'vehicle_documents.*.document_type' => ['nullable', 'exists:document_lists,id'],
            'vehicle_documents.*.document_file' => ['nullable', 'file', 'max:5120'],
            'document_type' => ['nullable', 'exists:document_lists,id'],
            'document_file' => ['nullable', 'file', 'max:5120'],
        ]);

        return Validator::make($data, $rules)->validate();
    }

    protected function handleStep0(Request $request, array $data): User
    {
        try {
            $user = $this->getAuthenticatedUser($request);
        } catch (\Exception $e) {
            // If user not found, create a new incomplete user for step 0
            $userData = [
                'role_id' => 2,  // Driver role
                'status' => 'incomplete',
                'device_token' => $request->input('device_token', ''),
            ];

            $user = User::create($userData);

            // Generate bearer token for the new user
            $authData = $this->createAuthToken($user, $request->input('device_token', ''), 'phone');
        }

        $updateData = [
            'select_latitude' => $data['latitude'],
            'select_longitude' => $data['longitude'],
            'step_0' => 1,
        ];

        if (isset($data['city_id'])) {
            $updateData['city_id'] = $data['city_id'];
        }

        $user->update($updateData);

        return $user;
    }

    protected function handleStep1(Request $request, array $data, User $user = null): User
    {
        if ($user) {
            $existingUser = $user;
            $isUpdate = true;
        } else {
            $existingUser = null;
            $isUpdate = false;

            if (!empty($data['email'])) {
                $existingUser = User::where('email', $data['email'])
                    ->where('role_id', 2)
                    ->first();

                $isUpdate = $existingUser && $this->driverNeedsRegistration($existingUser);
            }

            if (!$isUpdate && !empty($data['name']) && !empty($data['phone'])) {
                $existingUser = User::where('name', $data['name'])
                    ->where('phone', $data['phone'])
                    ->where('role_id', 2)
                    ->first();
                $isUpdate = $existingUser !== null;
            }
        }

        $userData = [
            'status' => $isUpdate && $existingUser->status !== 'incomplete' ? $existingUser->status : 'under_review',
            'phone_verified_at' => $isUpdate ? $existingUser->phone_verified_at : now(),
            'device_token' => $data['device_token'] ?? ($isUpdate ? $existingUser->device_token : null),
            'step_1' => 1,
        ];

        if (!empty($data['name']))
            $userData['name'] = $data['name'];
        if (!empty($data['phone']))
            $userData['phone'] = $data['phone'];
        if (!empty($data['email']))
            $userData['email'] = $data['email'];
        if (!empty($data['date_of_birth']))
            $userData['date_of_birth'] = $data['date_of_birth'];

        if ($request->hasFile('driver_profile_image')) {
            $userData['profile_photo'] = $request->file('driver_profile_image')->store('drivers/profile-photos', 'public');
        }

        if ($isUpdate) {
            $user = $existingUser;
            $user->update($userData);

            if (empty($user->referral_code)) {
                $user->generateReferralCode();
            }
        } else {
            $userData['role_id'] = 2;
            $user = User::create($userData);

            if (empty($user->referral_code)) {
                $user->generateReferralCode();
            }
        }
        if (!empty($data['referral_code'])) {
            if (!empty($user->referral_code) && $user->referral_code === $data['referral_code']) {
                throw new \Exception('You cannot use your own referral code.');
            }
            $referrer = User::where('referral_code', $data['referral_code'])
                ->where('role_id', 2)
                ->first();
            if ($referrer) {
                $user->update(['referred_by' => $referrer->id]);

                $this->processReferralRewards($user, $referrer);
            } else {
                throw new \Exception('Invalid referral code. Only driver referral codes can be used.');
            }
        }

        return $user;
    }

    protected function processReferralRewards(User $referredUser, User $referrer): void
    {
        try {
            // Ensure both users have the same role_id (role_id 2 for drivers)
            if ($referredUser->role_id !== $referrer->role_id || $referredUser->role_id !== 2) {
                return;
            }

            $walletService = app(\App\Services\WalletService::class);

            $settings = \App\Models\DriverSearchSetting::getActive();

            $referrerReward = $settings->getReferrerReward($referredUser->role_id);
            $referredReward = $settings->getReferredReward($referredUser->role_id);
            $walletService->addReferralBonus(
                $referredUser,
                $referredReward,
                $referrer->referral_code
            );

            $walletService->addReferralBonus(
                $referrer,
                $referrerReward,
                "Referral bonus for {$referredUser->name}"
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }

    protected function handleStep2(Request $request, array $data, User $user): Vehicle
    {
        $vehicleData = [
            'ride_type_id' => $data['ride_type_id'] ?? 1,
            'brand' => 'Unknown',  // Will be updated in Step 3
            'model' => 'Unknown',  // Will be updated in Step 3
            'year' => date('Y'),  // Will be updated in Step 3
            'registration_number' => $data['registration_number'] ?? 'TEMP-' . $user->id,
            'license_plate' => $data['registration_number'] ?? 'TEMP',
            'color' => 'Unknown',
            'registration_expiry' => now()->addYears(5),
            'insurance_expiry' => now()->addYears(1),
            'status' => 'active',
        ];

        if ($user->vehicles()->exists()) {
            $vehicle = $user->vehicles()->first();
            $vehicle->update($vehicleData);
            $vehicle->update(['step_2' => 1]);
        } else {
            $vehicle = $user->vehicles()->create($vehicleData);
        }

        $user->update(['step_2' => 1]);

        return $vehicle;
    }

    protected function handleStep3(Request $request, array $data, User $user, ?Vehicle $vehicle): void
    {
        $this->createOrUpdateDriverProfile($user, $data);

        if ($vehicle && (!empty($data['make']) || !empty($data['model']) || !empty($data['year']))) {
            $vehicleUpdateData = [];
            if (!empty($data['make']))
                $vehicleUpdateData['brand'] = $data['make'];
            if (!empty($data['model']))
                $vehicleUpdateData['model'] = $data['model'];
            if (!empty($data['year']))
                $vehicleUpdateData['year'] = $data['year'];

            $vehicle->update($vehicleUpdateData);
            $vehicle->update(['step_3' => 1]);
        }

        $documentLists = \App\Models\DocumentList::where('is_active', true)->get();

        $commonDriverDocuments = ['government_id_front', 'government_id_back', 'goverment_id_front', 'goverment_id_back'];
        foreach ($commonDriverDocuments as $docType) {
            if ($request->hasFile($docType)) {
                $path = $request->file($docType)->store('documents/driver', 'public');
                $this->updateDocumentWithStatusCheck($user->documents(), $docType, $path, null);
            }
        }

        // Handle driver documents with _d suffix (e.g., driver_license_d, insurance_d)
        $this->handleDriverDocumentsWithSuffix($request, $user);

        // Handle vehicle documents that might be uploaded without matching DocumentList field names
        if ($vehicle) {
            $this->handleVehicleDocumentsDirect($request, $vehicle, $documentLists);
        }

        foreach ($documentLists as $documentList) {
            $fieldName = $this->getDocumentFieldName($documentList->name);

            if ($documentList->type === 'driver') {
                if (in_array($fieldName . '_front', $commonDriverDocuments) || in_array($fieldName . '_back', $commonDriverDocuments)) {
                    continue;
                }

                $frontFile = $this->getUploadedFileFromVariants(
                    $request,
                    $this->buildRequestFieldVariants($fieldName . '_front', $documentList->name . '_front')
                );
                if ($frontFile) {
                    $path = $frontFile->store('documents/' . $fieldName, 'public');
                    $this->updateDocumentWithStatusCheck($user->documents(), $fieldName . '_front', $path, null);
                }

                $backFile = $this->getUploadedFileFromVariants(
                    $request,
                    $this->buildRequestFieldVariants($fieldName . '_back', $documentList->name . '_back')
                );
                if ($backFile) {
                    $path = $backFile->store('documents/' . $fieldName, 'public');
                    $this->updateDocumentWithStatusCheck($user->documents(), $fieldName . '_back', $path, null);
                }

                $singleFile = $this->getUploadedFileFromVariants(
                    $request,
                    $this->buildRequestFieldVariants($fieldName, $documentList->name)
                );
                if ($singleFile) {
                    $path = $singleFile->store('documents/' . $fieldName, 'public');
                    $this->updateDocumentWithStatusCheck($user->documents(), $fieldName, $path, null);
                }

                // Also check for _d suffix variant (e.g., driver_license_d, insurance_d)
                $suffixFile = $this->getUploadedFileFromVariants(
                    $request,
                    $this->buildRequestFieldVariants($fieldName . '_d', $documentList->name . '_d')
                );
                if ($suffixFile) {
                    $path = $suffixFile->store('documents/' . $fieldName, 'public');
                    $this->updateDocumentWithStatusCheck($user->documents(), $fieldName, $path, null);
                }

                if ($fieldName === 'live_selfie' || $fieldName === 'selfie') {
                    if ($request->hasFile('selfi')) {
                        $path = $request->file('selfi')->store('documents/' . $fieldName, 'public');
                        $this->updateDocumentWithStatusCheck($user->documents(), $fieldName, $path, null);
                    }
                }
            } else {
                if ($vehicle) {
                    $vehicleFile = $this->getUploadedFileFromVariants(
                        $request,
                        $this->buildRequestFieldVariants($fieldName, $documentList->name)
                    );
                } else {
                    $vehicleFile = null;
                }

                if ($vehicle && $vehicleFile) {
                    $path = $vehicleFile->store('documents/vehicle', 'public');
                    $this->updateDocumentWithStatusCheck($vehicle->documents(), $fieldName, $path, null);
                } else if ($vehicle && !$user->is_register) {
                    $existingDoc = $vehicle->documents()->where('type', $fieldName)->first();
                    if (!$existingDoc) {
                        $vehicle->documents()->create([
                            'type' => $fieldName,
                            'status' => 'pending'
                        ]);
                    }
                }
            }
        }

        $selfieProcessed = false;
        foreach ($documentLists as $documentList) {
            $fieldName = $this->getDocumentFieldName($documentList->name);
            if ($fieldName === 'live_selfie' || $fieldName === 'selfie') {
                $selfieProcessed = true;
                break;
            }
        }

        if (!$selfieProcessed && $request->hasFile('selfi')) {
            $path = $request->file('selfi')->store('documents/selfie', 'public');
            $this->updateDocumentWithStatusCheck($user->documents(), 'selfie', $path, null);
        }

        $this->handleLegacyDocumentUploads($request, $data, $user, $vehicle);

        $this->markDocumentNotificationsAsUploaded($user, $documentLists);

        $stepThreeCompleted = $this->hasCompletedRequiredDocuments($user, $vehicle, $documentLists);

        $user->update(['step_3' => $stepThreeCompleted ? 1 : 0]);

        if ($stepThreeCompleted) {
            $user->update(['is_register' => 1]);
        }
    }

    protected function handleDriverDocumentsWithSuffix(Request $request, User $user): void
    {
        // Get all uploaded files from the request
        $allFiles = $request->allFiles();

        // Map of common field names with _d suffix to their normalized document types
        // Only process files with _d suffix here (driver documents)
        $documentTypeMap = [
            'driver_license_d' => 'driver_license',
            'insurance_d' => 'insurance',
            'id_proof_d' => 'id_proof',
            'police_verification_d' => 'police_verification',
            'address_proof_d' => 'address_proof',
            'background_check_d' => 'background_check',
        ];

        foreach ($allFiles as $fieldName => $file) {
            if (!($file instanceof UploadedFile)) {
                continue;
            }

            if (!str_ends_with($fieldName, '_d')) {
                continue;
            }

            $documentType = null;

            // Check if it's a known field in the map
            if (isset($documentTypeMap[$fieldName])) {
                $documentType = $documentTypeMap[$fieldName];
            } else {
                // Strip _d suffix to get the base document type
                $documentType = substr($fieldName, 0, -2);  // Remove '_d' suffix
            }

            if ($documentType) {
                // Store the file
                $path = $file->store('documents/' . $documentType, 'public');

                // Update or create the document record as driver document
                $this->updateDocumentWithStatusCheck($user->documents(), $documentType, $path, null);
            }
        }
    }

    protected function handleVehicleDocumentsDirect(Request $request, Vehicle $vehicle, $documentLists): void
    {
        // Get all uploaded files from the request
        $allFiles = $request->allFiles();

        // Build a list of all DocumentList field names to avoid processing files that will be handled by the main loop
        $documentListFieldNames = [];
        foreach ($documentLists as $documentList) {
            $fieldName = $this->getDocumentFieldName($documentList->name);
            $documentListFieldNames[] = strtolower($fieldName);
            $documentListFieldNames[] = strtolower(str_replace('_', '', $fieldName));
        }

        // Common vehicle document field names that might be uploaded directly
        // These will be processed here if they don't match a DocumentList entry
        $commonVehicleFields = ['insurance', 'registration', 'permit', 'fitness', 'vehicle_photo'];

        foreach ($allFiles as $fieldName => $file) {
            if (!($file instanceof UploadedFile)) {
                continue;
            }

            // Skip files with _d suffix (those are driver documents, already processed)
            if (str_ends_with($fieldName, '_d')) {
                continue;
            }

            $normalizedFieldName = strtolower($fieldName);

            // Skip if this field name matches a DocumentList entry (will be processed by main loop)
            if (
                in_array($normalizedFieldName, $documentListFieldNames) ||
                in_array(str_replace('_', '', $normalizedFieldName), $documentListFieldNames)
            ) {
                continue;
            }

            // Check if it's a common vehicle document field name
            if (in_array($normalizedFieldName, $commonVehicleFields)) {
                // Find the matching vehicle document type from DocumentList
                $documentType = null;
                foreach ($documentLists as $documentList) {
                    if ($documentList->type === 'vehicle') {
                        $docFieldName = $this->getDocumentFieldName($documentList->name);
                        $normalizedDocName = strtolower(str_replace('_', '', $docFieldName));
                        $normalizedDocNameFull = strtolower($docFieldName);

                        // Match common names to DocumentList entries
                        if (($normalizedFieldName === 'insurance' && (str_contains($normalizedDocName, 'insurance') || str_contains($normalizedDocNameFull, 'insurance'))) ||
                            ($normalizedFieldName === 'registration' && (str_contains($normalizedDocName, 'registration') || str_contains($normalizedDocNameFull, 'registration'))) ||
                            ($normalizedFieldName === 'permit' && (str_contains($normalizedDocName, 'permit') || str_contains($normalizedDocNameFull, 'permit'))) ||
                            ($normalizedFieldName === 'fitness' && (str_contains($normalizedDocName, 'fitness') || str_contains($normalizedDocNameFull, 'fitness'))) ||
                            ($normalizedFieldName === 'vehicle_photo' && (str_contains($normalizedDocName, 'photo') || str_contains($normalizedDocNameFull, 'photo')))
                        ) {
                            $documentType = $docFieldName;
                            break;
                        }
                    }
                }

                // If no match found in DocumentList, use the field name as document type
                if (!$documentType) {
                    $documentType = $normalizedFieldName;
                }

                // Store the file as vehicle document
                $path = $file->store('documents/vehicle', 'public');
                $this->updateDocumentWithStatusCheck($vehicle->documents(), $documentType, $path, null);
            }
        }
    }

    protected function markDocumentNotificationsAsUploaded(User $user, $documentLists): void
    {
        foreach ($documentLists as $documentList) {
            $fieldName = $this->getDocumentFieldName($documentList->name);
            $hasDocument = false;

            if ($documentList->type === 'driver') {
                $hasFront = $user->documents()->where('type', $fieldName . '_front')->exists();
                $hasBack = $user->documents()->where('type', $fieldName . '_back')->exists();
                $hasSingle = $user->documents()->where('type', $fieldName)->exists();
                $hasDocument = $hasFront || $hasBack || $hasSingle;
            } else {
                foreach ($user->vehicles as $vehicle) {
                    if ($vehicle->documents()->where('type', $fieldName)->exists()) {
                        $hasDocument = true;
                        break;
                    }
                }
            }

            if ($hasDocument) {
                \App\Models\DriverDocumentNotification::where('driver_id', $user->id)
                    ->where('document_list_id', $documentList->id)
                    ->where('is_uploaded', false)
                    ->update([
                        'is_uploaded' => true,
                        'uploaded_at' => now(),
                    ]);
            }
        }
    }

    protected function handleLegacyDocumentUploads(Request $request, array $data, User $user, ?Vehicle $vehicle): void
    {
        if ($vehicle && !empty($data['document_type']) && $request->hasFile('document_file')) {
            $documentFile = $request->file('document_file');
            $documentPath = $documentFile->store('documents/vehicle', 'public');

            $documentList = \App\Models\DocumentList::find($data['document_type']);

            if ($documentList) {
                $docType = $this->getDocumentFieldName($documentList->name);
                $this->updateDocumentWithStatusCheck($vehicle->documents(), $docType, $documentPath, null);
            }
        }

        if ($vehicle && !empty($data['vehicle_documents_type']) && !empty($data['vehicle_documents_file'])) {
            $documentTypes = $data['vehicle_documents_type'];
            $documentFiles = $data['vehicle_documents_file'];

            foreach ($documentTypes as $index => $documentTypeId) {
                $fileKey = "vehicle_documents_file.{$index}";
                if ($request->hasFile($fileKey) && !empty($documentTypeId)) {
                    $documentFile = $request->file($fileKey);
                    $documentPath = $documentFile->store('documents/vehicle', 'public');

                    $documentList = \App\Models\DocumentList::find($documentTypeId);

                    if ($documentList) {
                        $docType = $this->getDocumentFieldName($documentList->name);
                        $this->updateDocumentWithStatusCheck($vehicle->documents(), $docType, $documentPath, null);
                    }
                }
            }
        }

        if ($vehicle && !empty($data['vehicle_documents'])) {
            foreach ($data['vehicle_documents'] as $index => $docData) {
                $fileKey = "vehicle_documents.{$index}.document_file";
                if ($request->hasFile($fileKey)) {
                    $documentFile = $request->file($fileKey);
                    $documentPath = $documentFile->store('documents/vehicle', 'public');

                    $documentList = \App\Models\DocumentList::find($docData['document_type']);

                    if ($documentList) {
                        $docType = $this->getDocumentFieldName($documentList->name);
                        $this->updateDocumentWithStatusCheck($vehicle->documents(), $docType, $documentPath, null);
                    }
                }
            }
        }
    }

    protected function updateDocumentWithStatusCheck($documentsQuery, string $docType, string $fileFrontPath, ?string $fileBackPath = null): void
    {
        $existingDocument = $documentsQuery->where('type', $docType)->first();

        $updateData = [];

        if ($fileFrontPath) {
            $updateData['file_front'] = $fileFrontPath;
        }

        if ($fileBackPath) {
            $updateData['file_back'] = $fileBackPath;
        }

        if ($existingDocument) {
            if ($existingDocument->status === 'rejected') {
                $updateData['status'] = 'pending';
                $updateData['rejection_reason'] = null;
            }
            $existingDocument->update($updateData);
        } else {
            $updateData['status'] = 'pending';
            $documentsQuery->create(array_merge([
                'type' => $docType,
            ], $updateData));
        }
    }

    protected function getDocumentFieldName(string $documentName): string
    {
        $fieldName = strtolower(str_replace(' ', '_', $documentName));

        $fieldName = str_replace(['_certificate', '_cert'], '', $fieldName);

        return $fieldName;
    }

    protected function buildRequestFieldVariants(string $normalizedField, ?string $originalField = null): array
    {
        $candidates = array_filter([$normalizedField, $originalField]);
        $variants = [];

        foreach ($candidates as $candidate) {
            $variants[] = $candidate;
            $variants[] = Str::lower($candidate);
            $variants[] = Str::upper($candidate);
            $variants[] = Str::snake($candidate);
            $variants[] = Str::camel($candidate);
            $variants[] = Str::kebab($candidate);
        }

        return array_values(array_unique(array_filter($variants)));
    }

    protected function getUploadedFileFromVariants(Request $request, array $variants): ?UploadedFile
    {
        foreach ($variants as $variant) {
            if ($variant && $request->hasFile($variant)) {
                return $request->file($variant);
            }
        }

        return null;
    }

    protected function hasCompletedRequiredDocuments(User $user, ?Vehicle $vehicle, \Illuminate\Support\Collection $documentLists): bool
    {
        $requiredDocuments = $documentLists->filter(function ($document) {
            return (bool) ($document->is_required ?? false);
        });

        if ($requiredDocuments->isEmpty()) {
            return true;
        }

        return $requiredDocuments->every(function ($documentList) use ($user, $vehicle) {
            return $this->hasUploadedRequiredDocument($user, $vehicle, $documentList);
        });
    }

    protected function hasUploadedRequiredDocument(User $user, ?Vehicle $vehicle, \App\Models\DocumentList $documentList): bool
    {
        $fieldName = $this->getDocumentFieldName($documentList->name);

        if ($documentList->type === 'driver') {
            $documentTypes = $this->buildDriverDocumentTypes($fieldName);

            return $user
                ->documents()
                ->whereIn('type', $documentTypes)
                ->where(function ($query) {
                    $query
                        ->whereNotNull('file_front')
                        ->orWhereNotNull('file_back');
                })
                ->exists();
        }

        if ($documentList->type === 'vehicle') {
            if (!$vehicle) {
                return false;
            }

            return $vehicle
                ->documents()
                ->where('type', $fieldName)
                ->where(function ($query) {
                    $query
                        ->whereNotNull('file_front')
                        ->orWhereNotNull('file_back');
                })
                ->exists();
        }

        return false;
    }

    protected function buildDriverDocumentTypes(string $fieldName): array
    {
        $documentTypes = [
            $fieldName,
            "{$fieldName}_front",
            "{$fieldName}_back",
        ];

        if ($fieldName === 'government_id') {
            $documentTypes[] = 'goverment_id_front';
            $documentTypes[] = 'goverment_id_back';
        }

        if (in_array($fieldName, ['selfie', 'live_selfie'], true)) {
            $documentTypes[] = 'selfi';
        }

        return array_values(array_unique(array_filter($documentTypes)));
    }

    protected function documentMatchesActiveList(string $docType, $activeDocumentListEntries): bool
    {
        if (empty($docType)) {
            return false;
        }

        foreach ($activeDocumentListEntries as $documentList) {
            $documentName = $documentList->name;
            $normalizedType = $this->getDocumentFieldName($documentName);
            $normalizedTypeWithSpace = strtolower(str_replace(' ', '_', $documentName));

            if ($docType === $documentName) {
                return true;
            }

            $normalizedDocType = $this->getDocumentFieldName($docType);
            if (
                $normalizedDocType === $normalizedType ||
                $normalizedDocType === $normalizedTypeWithSpace ||
                $docType === $normalizedType ||
                $docType === $normalizedTypeWithSpace
            ) {
                return true;
            }

            if (
                strpos($docType, $normalizedType) !== false ||
                strpos($normalizedDocType, $normalizedType) !== false ||
                strpos($docType, $normalizedTypeWithSpace) !== false
            ) {
                return true;
            }
        }

        return false;
    }

    protected function getDocumentTypeForApi(string $documentName): string
    {
        return Str::snake(strtolower($documentName));
    }

    protected function normalizeDocumentTypeForResponse(string $documentType, string $documentCategory = 'driver'): string
    {
        if (empty($documentType)) {
            return '';
        }

        // Don't add _d suffix to documents that have _front or _back suffix
        if (str_ends_with($documentType, '_front') || str_ends_with($documentType, '_back')) {
            return $documentType;
        }

        // Special case: selfi doesn't get _d suffix
        if ($documentType === 'selfi' || $documentType === 'selfie') {
            return 'selfi';
        }

        // For driver documents, add _d suffix if not already present
        if ($documentCategory === 'driver' && !str_ends_with($documentType, '_d')) {
            return $documentType . '_d';
        }

        return $documentType;
    }

    protected function getDocumentNameFromType(string $documentType, string $documentCategory = 'driver'): string
    {
        if (empty($documentType)) {
            return '';
        }

        $hasFront = str_ends_with($documentType, '_front');
        $hasBack = str_ends_with($documentType, '_back');

        $baseType = preg_replace('/_(front|back)$/', '', $documentType);

        $documentLists = \App\Models\DocumentList::where('type', $documentCategory)
            ->where('is_active', true)
            ->get();

        $documentList = $documentLists->first(function ($doc) use ($baseType, $documentType) {
            $fieldName = $this->getDocumentFieldName($doc->name);
            $normalizedType = $this->getDocumentFieldName($documentType);

            if (
                $fieldName === $baseType ||
                $fieldName === $normalizedType ||
                $doc->name === $documentType ||
                $doc->name === $baseType ||
                $this->documentMatchesActiveList($documentType, collect([$doc]))
            ) {
                return true;
            }
            return false;
        });

        if ($documentList) {
            $name = $documentList->name;
            if ($hasFront) {
                $name .= ' Front';
            } elseif ($hasBack) {
                $name .= ' Back';
            }
            return $name;
        }

        $formattedName = str_replace('_', ' ', $documentType);
        $formattedName = ucwords($formattedName);

        return $formattedName;
    }

    protected function startAutoLocationUpdates(User $driver): bool
    {
        try {
            return $this->driverAutoLocationService->startAutoLocationUpdates($driver);
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function stopAutoLocationUpdates(User $driver): bool
    {
        try {
            return $this->driverAutoLocationService->stopAutoLocationUpdates($driver);
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function isAutoLocationActive(User $driver): bool
    {
        try {
            $cacheKey = "auto_location_driver_{$driver->id}";
            $cacheData = Cache::get($cacheKey);
            return $cacheData && $cacheData['is_active'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function createOrUpdateDriverProfile(User $user, array $data): void
    {
        $driverProfileData = [
            'driver_id' => $user->id,
            'city_id' => $data['city_id'] ?? null,
            'address' => $data['address'] ?? null,
            'license_number' => $data['license_number'] ?? null,
            'license_expiry' => $data['license_expiry'] ?? null,
            'identity_number' => $data['identity_number'] ?? null,
            'identity_type' => $data['identity_type'] ?? null,
            'identity_expiry' => $data['identity_expiry'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'bank_ifsc' => $data['bank_ifsc'] ?? null,
            'bank_branch' => $data['bank_branch'] ?? null,
            'account_holder_name' => $data['account_holder_name'] ?? null,
            'commission_rate' => $data['commission_rate'] ?? 10.0,  // Default 10% commission
            'total_trips' => 0,
            'completed_trips' => 0,
            'cancelled_trips' => 0,
            'total_earnings' => 0.0,
            'total_commission' => 0.0,
            'rating' => 0,  // Default rating
        ];

        $user->driverProfile()->updateOrCreate(
            ['driver_id' => $user->id],
            $driverProfileData
        );
    }

    public function getDriverProfile(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (!$authUser) {
            return response()->json([
                'success' => false,
                'message' => 'Driver not authenticated',
            ], 401);
        }

        if ($authUser->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only drivers can access this endpoint.',
            ], 403);
        }

        $user = User::with([
            'driverProfile.city',
            'vehicles.rideType',
            'vehicles.documents',
            'wallet',
            'documents',
            'currentLocation',
            'currentAttendanceSession',
            'referrer',
            'transactions',
            'supportTickets',
            'wallet.transactions',
            'driverAttendance',
            'driverLocations',
            'bookingsAsDriver.user',
            'bookingsAsDriver.rideType',
            'bookingsAsDriver.pickupZone.city',
            'bookingsAsDriver.dropoffZone.city',
            'bookingsAsDriver.transactions'
        ])->findOrFail($authUser->id);

        $driverData = [
            'id' => (string) $user->id,
            'name' => $user->name ?? '',
            'email' => $user->email ?? '',
            'phone' => $user->phone ?? '',
            'country_code' => $user->country_code ?? '',
            'gender' => $user->gender ?? '',
            'date_of_birth' => $user->date_of_birth ? (is_string($user->date_of_birth) ? $user->date_of_birth : $user->date_of_birth->format('Y-m-d')) : '',
            'profile_photo' => $this->getProfilePhotoUrl($user->profile_photo),
            'role_id' => (string) $user->role_id,
            'role' => 'driver',
            'status' => $user->status ?? '',
            'is_online' => (string) ($user->is_online ? 1 : 0),
            'is_verified' => (string) (($this->isDriverFullyVerified($user) && $user->hasApprovedVehicleRegistration()) ? 1 : 0),
            'verified_at' => $user->verified_at?->format('Y-m-d H:i:s') ?? '',
            'email_verified_at' => $user->email_verified_at?->format('Y-m-d H:i:s') ?? '',
            'phone_verified_at' => $user->phone_verified_at?->format('Y-m-d H:i:s') ?? '',
            'last_location_at' => $user->last_location_at?->format('Y-m-d H:i:s') ?? '',
            'last_latitude' => (string) ($user->last_latitude ?? ''),
            'last_longitude' => (string) ($user->last_longitude ?? ''),
            'select_latitude' => (string) ($user->select_latitude ?? ''),
            'select_longitude' => (string) ($user->select_longitude ?? ''),
            'referral_code' => $user->referral_code ?? '',
            'referred_by' => (string) ($user->referred_by ?? ''),
            'is_register' => (string) ($user->is_register ?? 0),
            'login_device' => (string) ($user->login_device ?? ''),
            'step_0' => (string) ($user->step_0 ?? 0),
            'step_1' => (string) ($user->step_1 ?? 0),
            'step_2' => (string) ($user->step_2 ?? 0),
            'step_3' => (string) ($user->step_3 ?? 0),
            'current_booking_id' => (string) ($user->current_booking_id ?? ''),
            'created_at' => $user->created_at?->format('Y-m-d H:i:s') ?? '',
            'updated_at' => $user->updated_at?->format('Y-m-d H:i:s') ?? '',
            'available_payment' => $this->getAvailablePaymentMethods(),
        ];

        $driverProfile = $user->driverProfile;
        $driverData['driver_profile'] = [
            'id' => (string) ($driverProfile?->id ?? ''),
            'city_id' => (string) ($driverProfile?->city_id ?? ''),
            'city_name' => $driverProfile?->city?->name ?? '',
            'address' => $driverProfile?->address ?? '',
            'license_number' => $driverProfile?->license_number ?? '',
            'license_expiry' => $driverProfile?->license_expiry ? (is_string($driverProfile->license_expiry) ? $driverProfile->license_expiry : $driverProfile->license_expiry->format('Y-m-d')) : '',
            'identity_number' => $driverProfile?->identity_number ?? '',
            'identity_type' => $driverProfile?->identity_type ?? '',
            'identity_expiry' => $driverProfile?->identity_expiry ? (is_string($driverProfile->identity_expiry) ? $driverProfile->identity_expiry : $driverProfile->identity_expiry->format('Y-m-d')) : '',
            'bank_name' => $driverProfile?->bank_name ?? '',
            'bank_account_number' => $driverProfile?->bank_account_number ?? '',
            'bank_ifsc' => $driverProfile?->bank_ifsc ?? '',
            'bank_branch' => $driverProfile?->bank_branch ?? '',
            'account_holder_name' => $driverProfile?->account_holder_name ?? '',
            'commission_rate' => (string) ($driverProfile?->commission_rate ?? '0'),
            'total_trips' => (string) ($driverProfile?->total_trips ?? '0'),
            'completed_trips' => (string) ($driverProfile?->completed_trips ?? '0'),
            'cancelled_trips' => (string) ($driverProfile?->cancelled_trips ?? '0'),
            'total_earnings' => (string) ($driverProfile?->total_earnings ?? '0'),
            'total_commission' => (string) ($driverProfile?->total_commission ?? '0'),
            'rating' => (string) round((float) ($user
                ->bookingsAsDriver()
                ->where('status', 'completed')
                ->whereNotNull('user_rating')
                ->avg('user_rating') ?? ($driverProfile?->rating ?? 0)), 2),
            'identity_verified_at' => $driverProfile?->identity_verified_at?->format('Y-m-d H:i:s') ?? '',
            'bank_verified_at' => $driverProfile?->bank_verified_at?->format('Y-m-d H:i:s') ?? '',
            'address_verified_at' => $driverProfile?->address_verified_at?->format('Y-m-d H:i:s') ?? '',
            'rejection_reason' => $driverProfile?->rejection_reason ?? '',
            'created_at' => $driverProfile?->created_at?->format('Y-m-d H:i:s') ?? '',
            'updated_at' => $driverProfile?->updated_at?->format('Y-m-d H:i:s') ?? '',
        ];

        $driverMetaData = $driverProfile?->meta_data ?? [];
        $vehicleRegistrationStatus = $driverMetaData['vehicle_registration_status']
            ?? (
                isset($driverMetaData['vehicle_registration_approved']) && $driverMetaData['vehicle_registration_approved']
                ? 'approved'
                : 'pending'
            );
        $vehicleRegistrationStatus = $vehicleRegistrationStatus ?: 'pending';
        $vehicleRegistrationReason = $driverMetaData['vehicle_registration_rejection_reason'] ?? '';

        $vehicle = $user->vehicles()->first();
        $driverData['vehicle'] = $vehicle ? [
            'id' => (string) $vehicle->id,
            'brand' => $vehicle->brand ?? '',
            'model' => $vehicle->model ?? '',
            'year' => (string) ($vehicle->year ?? ''),
            'registration_number' => $vehicle->registration_number ?? '',
            'license_plate' => $vehicle->license_plate ?? '',
            'color' => $vehicle->color ?? '',
            'ride_type_id' => (string) ($vehicle->ride_type_id ?? ''),
            'ride_type_name' => $vehicle->rideType?->name ?? '',
            'status' => $vehicle->status ?? '',
            'registration_status' => $vehicleRegistrationStatus,
            'registration_status_label' => ucfirst($vehicleRegistrationStatus),
            'registration_rejection_reason' => $vehicleRegistrationStatus === 'rejected' ? $vehicleRegistrationReason : '',
            'registration_expiry' => $vehicle->registration_expiry ? (is_string($vehicle->registration_expiry) ? $vehicle->registration_expiry : $vehicle->registration_expiry->format('Y-m-d')) : '',
            'insurance_expiry' => $vehicle->insurance_expiry ? (is_string($vehicle->insurance_expiry) ? $vehicle->insurance_expiry : $vehicle->insurance_expiry->format('Y-m-d')) : '',
            'created_at' => $vehicle->created_at?->format('Y-m-d H:i:s') ?? '',
            'updated_at' => $vehicle->updated_at?->format('Y-m-d H:i:s') ?? '',
        ] : [
            'id' => '',
            'brand' => '',
            'model' => '',
            'year' => '',
            'registration_number' => '',
            'license_plate' => '',
            'color' => '',
            'ride_type_id' => '',
            'ride_type_name' => '',
            'status' => '',
            'registration_status' => '',
            'registration_status_label' => '',
            'registration_rejection_reason' => '',
            'registration_expiry' => '',
            'insurance_expiry' => '',
            'created_at' => '',
            'updated_at' => '',
        ];

        $wallet = $user->wallet;
        $driverData['wallet'] = [
            'id' => (string) ($wallet?->id ?? ''),
            'balance' => (string) ($wallet?->balance ?? '0'),
            'currency' => $wallet?->currency ?? '',
            'created_at' => $wallet?->created_at?->format('Y-m-d H:i:s') ?? '',
            'updated_at' => $wallet?->updated_at?->format('Y-m-d H:i:s') ?? '',
        ];

        $driverDocuments = $user->documents()->get();
        $requiredDriverDocumentTypes = \App\Models\DocumentList::where('type', 'driver')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $allDriverDocuments = [];

        foreach ($driverDocuments as $document) {
            $documentType = $document->type ?? '';
            if ($documentType === 'selfie') {
                $documentType = 'selfi';
            }

            // Normalize document type for API response (add _d suffix for driver documents)
            $normalizedType = $this->normalizeDocumentTypeForResponse($documentType, 'driver');

            $documentName = $this->getDocumentNameFromType($documentType, 'driver');

            $allDriverDocuments[] = [
                'id' => (string) $document->id,
                'type' => $normalizedType,
                'name' => $documentName,
                'number' => $document->number ?? '',
                'file_front' => $document->file_front ?? '',
                'file_back' => $document->file_back ?? '',
                'file_front_url' => $document->file_front ? asset('storage/' . $document->file_front) : '',
                'file_back_url' => $document->file_back ? asset('storage/' . $document->file_back) : '',
                'expiry_date' => $document->expiry_date ? (is_string($document->expiry_date) ? $document->expiry_date : $document->expiry_date->format('Y-m-d')) : '',
                'status' => $document->status ?? '',
                'rejection_reason' => $document->rejection_reason ?? '',
                'verified_at' => $document->verified_at?->format('Y-m-d H:i:s') ?? '',
                'verified_by' => (string) ($document->verified_by ?? ''),
                'created_at' => $document->created_at?->format('Y-m-d H:i:s') ?? '',
                'updated_at' => $document->updated_at?->format('Y-m-d H:i:s') ?? '',
                'is_new' => '0',
            ];
        }

        foreach ($requiredDriverDocumentTypes as $documentList) {
            $documentName = $documentList->name;
            $fieldName = $this->getDocumentFieldName($documentName);
            $documentTypes = $this->buildDriverDocumentTypes($fieldName);

            $exists = $user
                ->documents()
                ->whereIn('type', $documentTypes)
                ->where(function ($query) {
                    $query
                        ->whereNotNull('file_front')
                        ->orWhereNotNull('file_back');
                })
                ->exists();

            if (!$exists) {
                $allDriverDocuments[] = [
                    'id' => '',
                    'type' => $this->getDocumentTypeForApi($documentName),
                    'name' => $documentName,
                    'number' => '',
                    'file_front' => '',
                    'file_back' => '',
                    'file_front_url' => '',
                    'file_back_url' => '',
                    'expiry_date' => '',
                    'status' => 'pending',
                    'rejection_reason' => '',
                    'verified_at' => '',
                    'verified_by' => '',
                    'created_at' => '',
                    'updated_at' => '',
                    'is_new' => '1',
                ];
            }
        }

        $driverData['documents'] = $allDriverDocuments;

        if ($vehicle) {
            $vehicleDocuments = $vehicle->documents()->get();

            $requiredVehicleDocumentTypes = \App\Models\DocumentList::where('type', 'vehicle')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            $allVehicleDocuments = [];

            foreach ($vehicleDocuments as $document) {
                $documentType = $document->type ?? '';

                // Vehicle documents don't get _d suffix, use as-is
                $normalizedType = $this->normalizeDocumentTypeForResponse($documentType, 'vehicle');

                $documentName = $this->getDocumentNameFromType($documentType, 'vehicle');

                $allVehicleDocuments[] = [
                    'id' => (string) $document->id,
                    'type' => $normalizedType,
                    'name' => $documentName,
                    'number' => $document->number ?? '',
                    'file_front' => $document->file_front ?? '',
                    'file_back' => $document->file_back ?? '',
                    'file_front_url' => $document->file_front ? asset('storage/' . $document->file_front) : '',
                    'file_back_url' => $document->file_back ? asset('storage/' . $document->file_back) : '',
                    'expiry_date' => $document->expiry_date ? (is_string($document->expiry_date) ? $document->expiry_date : $document->expiry_date->format('Y-m-d')) : '',
                    'status' => $document->status ?? '',
                    'rejection_reason' => $document->rejection_reason ?? '',
                    'verified_at' => $document->verified_at?->format('Y-m-d H:i:s') ?? '',
                    'verified_by' => (string) ($document->verified_by ?? ''),
                    'created_at' => $document->created_at?->format('Y-m-d H:i:s') ?? '',
                    'updated_at' => $document->updated_at?->format('Y-m-d H:i:s') ?? '',
                    'is_new' => '0',
                ];
            }

            foreach ($requiredVehicleDocumentTypes as $documentList) {
                $documentName = $documentList->name;

                $exists = false;
                foreach ($vehicleDocuments as $document) {
                    $docType = $document->type ?? '';
                    if ($this->documentMatchesActiveList($docType, collect([$documentList]))) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $allVehicleDocuments[] = [
                        'id' => '',
                        'type' => $this->getDocumentTypeForApi($documentName),
                        'name' => $documentName,
                        'number' => '',
                        'file_front' => '',
                        'file_back' => '',
                        'file_front_url' => '',
                        'file_back_url' => '',
                        'expiry_date' => '',
                        'status' => 'pending',
                        'rejection_reason' => '',
                        'verified_at' => '',
                        'verified_by' => '',
                        'created_at' => '',
                        'updated_at' => '',
                        'is_new' => '1',
                    ];
                }
            }

            $driverData['vehicle_documents'] = $allVehicleDocuments;
        } else {
            $requiredVehicleDocumentTypes = \App\Models\DocumentList::where('type', 'vehicle')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            $driverData['vehicle_documents'] = $requiredVehicleDocumentTypes->map(function ($documentList) {
                return [
                    'id' => '',
                    'type' => $this->getDocumentTypeForApi($documentList->name),
                    'name' => $documentList->name,
                    'number' => '',
                    'file_front' => '',
                    'file_back' => '',
                    'file_front_url' => '',
                    'file_back_url' => '',
                    'expiry_date' => '',
                    'status' => 'pending',
                    'rejection_reason' => '',
                    'verified_at' => '',
                    'verified_by' => '',
                    'created_at' => '',
                    'updated_at' => '',
                    'is_new' => '1',
                ];
            })->toArray();
        }

        $currentLocation = $user->currentLocation;
        $driverData['current_location'] = $currentLocation ? [
            'id' => (string) $currentLocation->id,
            'latitude' => (string) ($currentLocation->latitude ?? ''),
            'longitude' => (string) ($currentLocation->longitude ?? ''),
            'address' => $currentLocation->address ?? '',
            'heading' => (string) ($currentLocation->heading ?? ''),
            'speed' => (string) ($currentLocation->speed ?? ''),
            'accuracy' => (string) ($currentLocation->accuracy ?? ''),
            'battery_level' => (string) ($currentLocation->battery_level ?? ''),
            'is_charging' => (string) ($currentLocation->is_charging ? 1 : 0),
            'is_active' => (string) ($currentLocation->is_active ? 1 : 0),
            'recorded_at' => $currentLocation->recorded_at?->format('Y-m-d H:i:s') ?? '',
        ] : [
            'id' => '',
            'latitude' => '',
            'longitude' => '',
            'address' => '',
            'heading' => '',
            'speed' => '',
            'accuracy' => '',
            'battery_level' => '',
            'is_charging' => '',
            'is_active' => '',
            'recorded_at' => '',
        ];

        $currentAttendance = $user->currentAttendanceSession;
        $driverData['current_attendance'] = $currentAttendance ? [
            'id' => (string) $currentAttendance->id,
            'online_time' => $currentAttendance->online_time?->format('Y-m-d H:i:s') ?? '',
            'offline_time' => $currentAttendance->offline_time?->format('Y-m-d H:i:s') ?? '',
            'total_online_hours' => (string) ($currentAttendance->total_online_hours ?? '0'),
            'status' => $currentAttendance->offline_time ? 'offline' : 'online',
        ] : [
            'id' => '',
            'online_time' => '',
            'offline_time' => '',
            'total_online_hours' => '',
            'status' => 'offline',
        ];

        $driverStats = $this->getDriverStatistics($user);
        $driverData['statistics'] = $driverStats;

        $allBookings = $user->bookingsAsDriver()->with(['user', 'rideType', 'pickupZone', 'dropoffZone', 'driver', 'driver.vehicles', 'driver.driverProfile'])->get();

        $recentBookings = $allBookings->take(10);
        $driverData['recent_bookings'] = $recentBookings->map(function ($booking) {
            return [
                'id' => (string) $booking->id,
                'booking_code' => $booking->booking_code ?? '',
                'status' => $booking->status ?? '',
                'payment_method' => $booking->payment_method ?? '',
                'payment_status' => $booking->payment_status ?? '',
                'pickup_address' => $booking->pickup_address ?? '',
                'dropoff_address' => $booking->dropoff_address ?? '',
                'pickup_latitude' => (string) ($booking->pickup_latitude ?? ''),
                'pickup_longitude' => (string) ($booking->pickup_longitude ?? ''),
                'dropoff_latitude' => (string) ($booking->dropoff_latitude ?? ''),
                'dropoff_longitude' => (string) ($booking->dropoff_longitude ?? ''),
                'estimated_distance' => (string) ($booking->estimated_distance ?? '0'),
                'actual_distance' => (string) ($booking->actual_distance ?? '0'),
                'estimated_duration' => (string) ($booking->estimated_duration ?? '0'),
                'actual_duration' => (string) ($booking->actual_duration ?? '0'),
                'base_fare' => (string) ($booking->base_fare ?? '0'),
                'distance_fare' => (string) ($booking->distance_fare ?? '0'),
                'time_fare' => (string) ($booking->time_fare ?? '0'),
                'waiting_charge' => (string) ($booking->waiting_charge ?? '0'),
                'cancellation_charge' => (string) ($booking->cancellation_charge ?? '0'),
                'night_charge' => (string) ($booking->night_charge ?? '0'),
                'surge_multiplier' => (string) ($booking->surge_multiplier ?? '1'),
                'surge_amount' => (string) ($booking->surge_amount ?? '0'),
                'subtotal' => (string) ($booking->subtotal ?? '0'),
                'tax_amount' => (string) ($booking->tax_amount ?? '0'),
                'total_amount' => (string) ($booking->total_amount ?? '0'),
                'admin_commission_rate' => (string) ($booking->admin_commission_rate ?? '0'),
                'admin_commission' => (string) ($booking->admin_commission ?? '0'),
                'platform_commission' => (string) ($booking->platform_commission ?? '0'),
                'driver_amount' => (string) ($booking->driver_amount ?? '0'),
                'discount_amount' => (string) ($booking->discount_amount ?? '0'),
                'wallet_amount' => (string) ($booking->wallet_amount ?? '0'),
                'online_paid_amount' => (string) ($booking->online_paid_amount ?? '0'),
                'cash_amount' => (string) ($booking->cash_amount ?? '0'),
                'promo_code' => $booking->promo_code ?? '',
                'user_rating' => (string) ($booking->user_rating ?? '0'),
                'user_review' => $booking->user_review ?? '',
                'driver_rating' => (string) ($booking->driver_rating ?? '0'),
                'driver_review' => $booking->driver_review ?? '',
                'waiting_time' => (string) ($booking->waiting_time ?? '0'),
                'otp' => $booking->otp ?? '',
                'trip_code' => $booking->trip_code ?? '',
                'scheduled_at' => $booking->scheduled_at?->format('Y-m-d H:i:s') ?? '',
                'started_at' => $booking->started_at?->format('Y-m-d H:i:s') ?? '',
                'completed_at' => $booking->completed_at?->format('Y-m-d H:i:s') ?? '',
                'cancelled_at' => $booking->cancelled_at?->format('Y-m-d H:i:s') ?? '',
                'cancellation_reason' => $booking->cancellation_reason ?? '',
                'cancelled_by_type' => $booking->cancelled_by_type ?? '',
                'cancelled_by_id' => (string) ($booking->cancelled_by_id ?? ''),
                'driver_arrival_time' => $booking->driver_arrival_time?->format('Y-m-d H:i:s') ?? '',
                'pickup_time' => $booking->pickup_time?->format('Y-m-d H:i:s') ?? '',
                'dropoff_time' => $booking->dropoff_time?->format('Y-m-d H:i:s') ?? '',
                'user' => $booking->user ? [
                    'id' => (string) $booking->user->id,
                    'name' => $booking->user->name ?? '',
                    'phone' => $booking->user->phone ?? '',
                    'profile_photo' => $this->getProfilePhotoUrl($booking->user->profile_photo),
                ] : [
                    'id' => '',
                    'name' => '',
                    'phone' => '',
                    'profile_photo' => '',
                ],
                'ride_type' => $booking->rideType ? [
                    'id' => (string) $booking->rideType->id,
                    'name' => $booking->rideType->name ?? '',
                    'description' => $booking->rideType->description ?? '',
                ] : [
                    'id' => '',
                    'name' => '',
                    'description' => '',
                ],
                'pickup_zone' => $booking->pickupZone ? [
                    'id' => (string) $booking->pickupZone->id,
                    'name' => $booking->pickupZone->name ?? '',
                ] : [
                    'id' => '',
                    'name' => '',
                ],
                'dropoff_zone' => $booking->dropoffZone ? [
                    'id' => (string) $booking->dropoffZone->id,
                    'name' => $booking->dropoffZone->name ?? '',
                ] : [
                    'id' => '',
                    'name' => '',
                ],
                'created_at' => $booking->created_at?->format('Y-m-d H:i:s') ?? '',
                'updated_at' => $booking->updated_at?->format('Y-m-d H:i:s') ?? '',
            ];
        })->toArray();

        $driverData['booking_statistics'] = [
            'total_bookings' => (string) $allBookings->count(),
            'completed_bookings' => (string) $allBookings->where('status', 'completed')->count(),
            'cancelled_bookings' => (string) $allBookings->where('status', 'cancelled')->count(),
            'pending_bookings' => (string) $allBookings->where('status', 'pending')->count(),
            'accepted_bookings' => (string) $allBookings->where('status', 'accepted')->count(),
            'started_bookings' => (string) $allBookings->where('status', 'started')->count(),
            'expired_bookings' => (string) $allBookings->where('status', 'expired')->count(),
            'total_earnings' => (string) $allBookings->where('status', 'completed')->sum('driver_amount'),
            'total_commission_paid' => (string) $allBookings->where('status', 'completed')->sum('admin_commission'),
            'total_distance' => (string) $allBookings->where('status', 'completed')->sum('actual_distance'),
            'total_duration' => (string) $allBookings->where('status', 'completed')->sum('actual_duration'),
            'average_rating_received' => (string) $allBookings->where('status', 'completed')->whereNotNull('driver_rating')->avg('driver_rating'),
            'average_rating_given' => (string) $allBookings->where('status', 'completed')->whereNotNull('user_rating')->avg('user_rating'),
            'total_waiting_time' => (string) $allBookings->sum('waiting_time'),
            'total_waiting_charges' => (string) $allBookings->sum('waiting_charge'),
            'total_surge_earnings' => (string) $allBookings->sum('surge_amount'),
        ];

        $driverData['payment_methods'] = [
            'cash' => (string) $allBookings->where('payment_method', 'cash')->count(),
            'wallet' => (string) $allBookings->where('payment_method', 'wallet')->count(),
            'online' => (string) $allBookings->where('payment_method', 'online')->count(),
            'split' => (string) $allBookings->where('payment_method', 'split')->count(),
        ];

        $monthlyEarnings = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            $monthBookings = $allBookings->filter(function ($booking) use ($monthStart, $monthEnd) {
                return $booking->created_at >= $monthStart && $booking->created_at <= $monthEnd;
            });

            $monthlyEarnings[] = [
                'month' => $month->format('Y-m'),
                'month_name' => $month->format('F Y'),
                'total_bookings' => (string) $monthBookings->count(),
                'completed_bookings' => (string) $monthBookings->where('status', 'completed')->count(),
                'total_earnings' => (string) $monthBookings->where('status', 'completed')->sum('driver_amount'),
                'total_commission' => (string) $monthBookings->where('status', 'completed')->sum('admin_commission'),
                'total_distance' => (string) $monthBookings->where('status', 'completed')->sum('actual_distance'),
                'average_rating' => (string) $monthBookings->where('status', 'completed')->whereNotNull('driver_rating')->avg('driver_rating'),
            ];
        }
        $driverData['monthly_earnings'] = $monthlyEarnings;

        $referrer = $user->referrer;
        $driverData['referrer'] = [
            'id' => (string) ($referrer?->id ?? ''),
            'name' => $referrer?->name ?? '',
            'phone' => $referrer?->phone ?? '',
            'referral_code' => $referrer?->referral_code ?? '',
        ];

        $driverData['referrals_count'] = (string) $user->referrals()->count();

        $transactions = $user->transactions()->latest()->limit(20)->get();
        $driverData['recent_transactions'] = $transactions->map(function ($transaction) {
            return [
                'id' => (string) $transaction->id,
                'transaction_id' => $transaction->transaction_id ?? '',
                'type' => $transaction->type ?? '',
                'amount' => (string) ($transaction->amount ?? '0'),
                'balance' => (string) ($transaction->balance ?? '0'),
                'description' => $transaction->description ?? '',
                'status' => $transaction->status ?? '',
                'payment_method' => $transaction->payment_method ?? '',
                'currency' => $transaction->currency ?? '',
                'gateway_transaction_id' => $transaction->gateway_transaction_id ?? '',
                'processed_at' => $transaction->processed_at?->format('Y-m-d H:i:s') ?? '',
                'failed_at' => $transaction->failed_at?->format('Y-m-d H:i:s') ?? '',
                'created_at' => $transaction->created_at?->format('Y-m-d H:i:s') ?? '',
            ];
        })->toArray();

        $supportTickets = $user->supportTickets()->latest()->limit(10)->get();
        $driverData['support_tickets'] = $supportTickets->map(function ($ticket) {
            return [
                'id' => (string) $ticket->id,
                'ticket_number' => $ticket->ticket_number ?? '',
                'category' => $ticket->category ?? '',
                'subject' => $ticket->subject ?? '',
                'priority' => $ticket->priority ?? '',
                'status' => $ticket->status ?? '',
                'booking_id' => (string) ($ticket->booking_id ?? ''),
                'last_reply_at' => $ticket->last_reply_at?->format('Y-m-d H:i:s') ?? '',
                'resolved_at' => $ticket->resolved_at?->format('Y-m-d H:i:s') ?? '',
                'closed_at' => $ticket->closed_at?->format('Y-m-d H:i:s') ?? '',
                'created_at' => $ticket->created_at?->format('Y-m-d H:i:s') ?? '',
            ];
        })->toArray();

        $walletTransactions = $user->wallet?->transactions()->latest()->limit(20)->get() ?? collect();
        $driverData['wallet_transactions'] = $walletTransactions->map(function ($transaction) {
            return [
                'id' => (string) $transaction->id,
                'type' => $transaction->type ?? '',
                'amount' => (string) ($transaction->amount ?? '0'),
                'balance' => (string) ($transaction->balance ?? '0'),
                'description' => $transaction->description ?? '',
                'status' => $transaction->status ?? '',
                'reference_type' => $transaction->reference_type ?? '',
                'reference_id' => (string) ($transaction->reference_id ?? ''),
                'created_at' => $transaction->created_at?->format('Y-m-d H:i:s') ?? '',
            ];
        })->toArray();

        $attendanceHistory = $user->driverAttendance()->latest()->limit(20)->get();
        $driverData['attendance_history'] = $attendanceHistory->map(function ($attendance) {
            return [
                'id' => (string) $attendance->id,
                'online_time' => $attendance->online_time?->format('Y-m-d H:i:s') ?? '',
                'offline_time' => $attendance->offline_time?->format('Y-m-d H:i:s') ?? '',
                'total_online_hours' => (string) ($attendance->total_online_hours ?? '0'),
                'status' => $attendance->offline_time ? 'completed' : 'active',
                'created_at' => $attendance->created_at?->format('Y-m-d H:i:s') ?? '',
            ];
        })->toArray();

        $locationHistory = $user->driverLocations()->latest()->limit(50)->get();
        $driverData['location_history'] = $locationHistory->map(function ($location) {
            return [
                'id' => (string) $location->id,
                'latitude' => (string) ($location->latitude ?? ''),
                'longitude' => (string) ($location->longitude ?? ''),
                'address' => $location->address ?? '',
                'heading' => (string) ($location->heading ?? ''),
                'speed' => (string) ($location->speed ?? ''),
                'accuracy' => (string) ($location->accuracy ?? ''),
                'battery_level' => (string) ($location->battery_level ?? ''),
                'is_charging' => (string) ($location->is_charging ? 1 : 0),
                'is_active' => (string) ($location->is_active ? 1 : 0),
                'recorded_at' => $location->recorded_at?->format('Y-m-d H:i:s') ?? '',
            ];
        })->toArray();

        $driverData['comprehensive_statistics'] = [
            'total_transactions' => (string) $user->transactions()->count(),
            'total_support_tickets' => (string) $user->supportTickets()->count(),
            'open_support_tickets' => (string) $user->supportTickets()->where('status', 'open')->count(),
            'resolved_support_tickets' => (string) $user->supportTickets()->where('status', 'resolved')->count(),
            'total_wallet_transactions' => (string) ($user->wallet?->transactions()->count() ?? 0),
            'total_attendance_sessions' => (string) $user->driverAttendance()->count(),
            'total_location_updates' => (string) $user->driverLocations()->count(),
            'total_transaction_amount' => (string) $user->transactions()->where('wallet_transactions.status', 'completed')->sum('amount'),
            'average_session_duration' => (string) $user->driverAttendance()->whereNotNull('offline_time')->avg('total_online_hours'),
            'total_online_hours' => (string) $user->driverAttendance()->sum('total_online_hours'),
        ];

        $tripHistory = $user->bookingsAsDriver()->with(['user.driverProfile', 'rideType', 'pickupZone', 'dropoffZone', 'transactions'])->orderBy('created_at', 'desc')->take(2)->get();
        $driverData['trip_history'] = $tripHistory->map(function ($booking) {
            return [
                'id' => (string) $booking->id,
                'booking_code' => $booking->booking_code ?? '',
                'trip_code' => $booking->trip_code ?? '',
                'status' => $booking->status ?? '',
                'payment_method' => $booking->payment_method ?? '',
                'payment_status' => $booking->payment_status ?? '',
                'pickup_address' => $booking->pickup_address ?? '',
                'dropoff_address' => $booking->dropoff_address ?? '',
                'pickup_latitude' => (string) ($booking->pickup_latitude ?? ''),
                'pickup_longitude' => (string) ($booking->pickup_longitude ?? ''),
                'dropoff_latitude' => (string) ($booking->dropoff_latitude ?? ''),
                'dropoff_longitude' => (string) ($booking->dropoff_longitude ?? ''),
                'estimated_distance' => (string) ($booking->estimated_distance ?? '0'),
                'actual_distance' => (string) ($booking->actual_distance ?? '0'),
                'estimated_duration' => (string) ($booking->estimated_duration ?? '0'),
                'actual_duration' => (string) ($booking->actual_duration ?? '0'),
                'base_fare' => (string) ($booking->base_fare ?? '0'),
                'distance_fare' => (string) ($booking->distance_fare ?? '0'),
                'time_fare' => (string) ($booking->time_fare ?? '0'),
                'waiting_charge' => (string) ($booking->waiting_charge ?? '0'),
                'cancellation_charge' => (string) ($booking->cancellation_charge ?? '0'),
                'night_charge' => (string) ($booking->night_charge ?? '0'),
                'surge_multiplier' => (string) ($booking->surge_multiplier ?? '1'),
                'surge_amount' => (string) ($booking->surge_amount ?? '0'),
                'subtotal' => (string) ($booking->subtotal ?? '0'),
                'tax_amount' => (string) ($booking->tax_amount ?? '0'),
                'total_amount' => (string) ($booking->total_amount ?? '0'),
                'admin_commission_rate' => (string) ($booking->admin_commission_rate ?? '0'),
                'admin_commission' => (string) ($booking->admin_commission ?? '0'),
                'platform_commission' => (string) ($booking->platform_commission ?? '0'),
                'driver_amount' => (string) ($booking->driver_amount ?? '0'),
                'discount_amount' => (string) ($booking->discount_amount ?? '0'),
                'wallet_amount' => (string) ($booking->wallet_amount ?? '0'),
                'online_paid_amount' => (string) ($booking->online_paid_amount ?? '0'),
                'cash_amount' => (string) ($booking->cash_amount ?? '0'),
                'promo_code' => $booking->promo_code ?? '',
                'user_rating' => (string) ($booking->user_rating ?? '0'),
                'user_review' => $booking->user_review ?? '',
                'driver_rating' => (string) ($booking->driver_rating ?? '0'),
                'driver_review' => $booking->driver_review ?? '',
                'waiting_time' => (string) ($booking->waiting_time ?? '0'),
                'otp' => $booking->otp ?? '',
                'scheduled_at' => $booking->scheduled_at?->format('Y-m-d H:i:s') ?? '',
                'started_at' => $booking->started_at?->format('Y-m-d H:i:s') ?? '',
                'completed_at' => $booking->completed_at?->format('Y-m-d H:i:s') ?? '',
                'cancelled_at' => $booking->cancelled_at?->format('Y-m-d H:i:s') ?? '',
                'cancellation_reason' => $booking->cancellation_reason ?? '',
                'cancelled_by_type' => $booking->cancelled_by_type ?? '',
                'cancelled_by_id' => (string) ($booking->cancelled_by_id ?? ''),
                'driver_arrival_time' => $booking->driver_arrival_time?->format('Y-m-d H:i:s') ?? '',
                'pickup_time' => $booking->pickup_time?->format('Y-m-d H:i:s') ?? '',
                'dropoff_time' => $booking->dropoff_time?->format('Y-m-d H:i:s') ?? '',
                'user' => $booking->user ? [
                    'id' => (string) $booking->user->id,
                    'name' => $booking->user->name ?? '',
                    'phone' => $booking->user->phone ?? '',
                    'profile_photo' => $this->getProfilePhotoUrl($booking->user->profile_photo),
                    'rating' => (string) ($booking->user->driverProfile?->rating ?? '0'),
                ] : [
                    'id' => '',
                    'name' => '',
                    'phone' => '',
                    'profile_photo' => '',
                    'rating' => '',
                ],
                'ride_type' => $booking->rideType ? [
                    'id' => (string) $booking->rideType->id,
                    'name' => $booking->rideType->name ?? '',
                    'description' => $booking->rideType->description ?? '',
                    'base_fare' => (string) ($booking->rideType->base_fare ?? '0'),
                    'per_km_rate' => (string) ($booking->rideType->per_km_rate ?? '0'),
                    'per_minute_rate' => (string) ($booking->rideType->per_minute_rate ?? '0'),
                ] : [
                    'id' => '',
                    'name' => '',
                    'description' => '',
                    'base_fare' => '',
                    'per_km_rate' => '',
                    'per_minute_rate' => '',
                ],
                'pickup_zone' => $booking->pickupZone ? [
                    'id' => (string) $booking->pickupZone->id,
                    'name' => $booking->pickupZone->name ?? '',
                    'city' => $booking->pickupZone->city?->name ?? '',
                ] : [
                    'id' => '',
                    'name' => '',
                    'city' => '',
                ],
                'dropoff_zone' => $booking->dropoffZone ? [
                    'id' => (string) $booking->dropoffZone->id,
                    'name' => $booking->dropoffZone->name ?? '',
                    'city' => $booking->dropoffZone->city?->name ?? '',
                ] : [
                    'id' => '',
                    'name' => '',
                    'city' => '',
                ],
                'transactions' => $booking->transactions->map(function ($transaction) {
                    return [
                        'id' => (string) $transaction->id,
                        'transaction_id' => $transaction->transaction_id ?? '',
                        'type' => $transaction->type ?? '',
                        'amount' => (string) ($transaction->amount ?? '0'),
                        'status' => $transaction->status ?? '',
                        'payment_method' => $transaction->payment_method ?? '',
                        'created_at' => $transaction->created_at?->format('Y-m-d H:i:s') ?? '',
                    ];
                })->toArray(),
                'created_at' => $booking->created_at?->format('Y-m-d H:i:s') ?? '',
                'updated_at' => $booking->updated_at?->format('Y-m-d H:i:s') ?? '',
            ];
        })->toArray();

        $driverData['trip_history_statistics'] = [
            'total_trips' => (string) $tripHistory->count(),
            'completed_trips' => (string) $tripHistory->where('status', 'completed')->count(),
            'cancelled_trips' => (string) $tripHistory->where('status', 'cancelled')->count(),
            'pending_trips' => (string) $tripHistory->where('status', 'pending')->count(),
            'accepted_trips' => (string) $tripHistory->where('status', 'accepted')->count(),
            'started_trips' => (string) $tripHistory->where('status', 'started')->count(),
            'expired_trips' => (string) $tripHistory->where('status', 'expired')->count(),
            'total_earnings' => (string) $tripHistory->where('status', 'completed')->sum('driver_amount'),
            'total_commission_paid' => (string) $tripHistory->where('status', 'completed')->sum('admin_commission'),
            'total_distance_driven' => (string) $tripHistory->where('status', 'completed')->sum('actual_distance'),
            'total_time_driven' => (string) $tripHistory->where('status', 'completed')->sum('actual_duration'),
            'average_trip_earning' => (string) $tripHistory->where('status', 'completed')->avg('driver_amount'),
            'average_trip_distance' => (string) $tripHistory->where('status', 'completed')->avg('actual_distance'),
            'average_trip_duration' => (string) $tripHistory->where('status', 'completed')->avg('actual_duration'),
            'average_rating_received' => (string) $tripHistory->where('status', 'completed')->whereNotNull('driver_rating')->avg('driver_rating'),
            'average_rating_given' => (string) $tripHistory->where('status', 'completed')->whereNotNull('user_rating')->avg('user_rating'),
            'total_waiting_time' => (string) $tripHistory->sum('waiting_time'),
            'total_waiting_charges' => (string) $tripHistory->sum('waiting_charge'),
            'total_surge_earnings' => (string) $tripHistory->sum('surge_amount'),
            'total_night_charges' => (string) $tripHistory->sum('night_charge'),
            'completion_rate' => $tripHistory->count() > 0 ? (string) (($tripHistory->where('status', 'completed')->count() / $tripHistory->count()) * 100) : '0',
            'cancellation_rate' => $tripHistory->count() > 0 ? (string) (($tripHistory->where('status', 'cancelled')->count() / $tripHistory->count()) * 100) : '0',
            'acceptance_rate' => $tripHistory->count() > 0 ? (string) (($tripHistory->where('status', '!=', 'pending')->count() / $tripHistory->count()) * 100) : '0',
        ];

        $driverData['trip_history_by_status'] = [
            'completed' => $tripHistory->where('status', 'completed')->map(function ($booking) {
                return [
                    'id' => (string) $booking->id,
                    'booking_code' => $booking->booking_code ?? '',
                    'driver_amount' => (string) ($booking->driver_amount ?? '0'),
                    'admin_commission' => (string) ($booking->admin_commission ?? '0'),
                    'actual_distance' => (string) ($booking->actual_distance ?? '0'),
                    'actual_duration' => (string) ($booking->actual_duration ?? '0'),
                    'driver_rating' => (string) ($booking->driver_rating ?? '0'),
                    'user_rating' => (string) ($booking->user_rating ?? '0'),
                    'completed_at' => $booking->completed_at?->format('Y-m-d H:i:s') ?? '',
                ];
            })->values()->toArray(),
            'cancelled' => $tripHistory->where('status', 'cancelled')->map(function ($booking) {
                return [
                    'id' => (string) $booking->id,
                    'booking_code' => $booking->booking_code ?? '',
                    'cancellation_reason' => $booking->cancellation_reason ?? '',
                    'cancelled_by_type' => $booking->cancelled_by_type ?? '',
                    'cancelled_at' => $booking->cancelled_at?->format('Y-m-d H:i:s') ?? '',
                ];
            })->values()->toArray(),
            'pending' => $tripHistory->where('status', 'pending')->map(function ($booking) {
                return [
                    'id' => (string) $booking->id,
                    'booking_code' => $booking->booking_code ?? '',
                    'pickup_address' => $booking->pickup_address ?? '',
                    'dropoff_address' => $booking->dropoff_address ?? '',
                    'estimated_amount' => (string) ($booking->total_amount ?? '0'),
                    'created_at' => $booking->created_at?->format('Y-m-d H:i:s') ?? '',
                ];
            })->values()->toArray(),
        ];

        $driverData['performance_metrics'] = [
            'acceptance_rate' => $allBookings->count() > 0 ? (string) (($allBookings->where('status', '!=', 'pending')->count() / $allBookings->count()) * 100) : '0',
            'completion_rate' => $allBookings->count() > 0 ? (string) (($allBookings->where('status', 'completed')->count() / $allBookings->count()) * 100) : '0',
            'cancellation_rate' => $allBookings->count() > 0 ? (string) (($allBookings->where('status', 'cancelled')->count() / $allBookings->count()) * 100) : '0',
            'average_rating' => (string) $allBookings->where('status', 'completed')->whereNotNull('driver_rating')->avg('driver_rating'),
            'total_5_star_ratings' => (string) $allBookings->where('status', 'completed')->where('driver_rating', 5)->count(),
            'total_4_star_ratings' => (string) $allBookings->where('status', 'completed')->where('driver_rating', 4)->count(),
            'total_3_star_ratings' => (string) $allBookings->where('status', 'completed')->where('driver_rating', 3)->count(),
            'total_2_star_ratings' => (string) $allBookings->where('status', 'completed')->where('driver_rating', 2)->count(),
            'total_1_star_ratings' => (string) $allBookings->where('status', 'completed')->where('driver_rating', 1)->count(),
        ];

        $currentBooking = Booking::where('driver_id', $user->id)
            ->whereIn('status', ['searching', 'accepted', 'arrived', 'started', 'completed'])
            ->where('payment_status', 'pending')
            ->with(['user', 'rideType', 'driver', 'driver.vehicles', 'driver.driverProfile'])
            ->latest()
            ->first();

        $complete_trip_data = Booking::where('driver_id', $user->id)
            ->where('status', 'completed')
            ->where('payment_status', 'pending')
            ->with(['user', 'rideType', 'driver', 'driver.vehicles', 'driver.driverProfile'])
            ->latest()
            ->first();
        $allBookingsFormatted = $allBookings->map(function ($booking) use ($user) {
            return $this->formatCurrentBookingResponse($booking, $user);
        })->toArray();

        $response = [
            'success' => true,
            'message' => 'Driver profile retrieved successfully',
            'data' => $driverData,
            'all_bookings' => null,  // $allBookingsFormatted,
        ];

        if ($currentBooking) {
            $response['current_booking'] = $this->formatCurrentBookingResponse($currentBooking, $user);
            $response['transaction_id'] = $currentBooking->transactions->first()->transaction_id ?? null;
        } else {
            $response['current_booking'] = null;
            $response['transaction_id'] = null;
        }

        $response['complete_trip'] = $complete_trip_data
            ? $this->formatCurrentBookingResponse($complete_trip_data, $user, true)
            : null;

        $weeklyGoal = $this->getWeeklyGoal($user);
        $nightRidersBonus = $this->getNightRidersBonus($user);

        // Credit bonuses if goals are completed
        if ($weeklyGoal && isset($weeklyGoal['is_completed']) && $weeklyGoal['is_completed'] == '1') {
            $this->creditWeeklyGoalBonus($user, $weeklyGoal);
        }

        if ($nightRidersBonus && isset($nightRidersBonus['is_completed']) && $nightRidersBonus['is_completed'] == '1') {
            $this->creditNightRidersBonus($user, $nightRidersBonus);
        }

        $response['data']['weekly_goal'] = $weeklyGoal;
        $response['data']['night_riders_bonus'] = $nightRidersBonus;

        return response()->json($response);
    }

    private function getCancellationChargeForBooking(Booking $booking): string
    {
        try {
            $city = \App\Models\City::find($booking->city_id);
            if ($city && $booking->ride_type_id) {
                $policy = $this->cityService->getCancellationPolicy($city, $booking->ride_type_id);

                if ($policy) {
                    // Use cancellation policy fee (for new bookings, trip amount is 0, so just return fixed fee)
                    return (string) ($policy->cancellation_fee ?? '0');
                }
            }
        } catch (\Exception $e) {
        }

        // Fallback to ride type's cancellation charge if policy not found
        return (string) ($booking->ride_type->cancellation_charge ?? '0');
    }

    private function formatCurrentBookingResponse(Booking $booking, User $driver, bool $includeFullDetails = false): array
    {
        $vehicle = $driver->vehicles()->first();
        $driverProfile = $driver->driverProfile;

        $passenger = [
            'name' => $booking->user->name ?? '',
            'phone' => $booking->user->phone ?? '',
            'photo' => $this->getProfilePhotoUrl($booking->user->profile_photo ?? ''),
            'rating' => '0',  // Default rating for passengers
        ];

        $tripDetails = [
            'distance' => (string) ($booking->estimated_distance ?? $booking->distance ?? '0.00'),
            'fare' => (string) ($booking->estimated_fare ?? $booking->final_fare ?? $booking->total_amount ?? '0.00'),
            'duration' => (string) ($booking->estimated_duration ?? $booking->duration ?? '0'),
        ];

        $bookingData = [
            'id' => (string) $booking->id,
            'booking_code' => $booking->booking_code ?? '',
            'user_id' => (string) ($booking->user_id ?? ''),
            'driver_id' => (string) ($booking->driver_id ?? ''),
            'pickup_address' => $booking->pickup_address ?? '',
            'dropoff_address' => $booking->dropoff_address ?? '',
            'status' => $booking->status ?? '',
            'payment_method' => $booking->payment_method ?? '',
            'payment_status' => $booking->payment_status ?? '',
            'estimated_fare' => (string) ($booking->estimated_fare ?? $booking->total_amount ?? '0.00'),
            'final_fare' => (string) ($booking->total_amount ?? $booking->estimated_fare ?? '0.00'),
            'otp' => $booking->otp ?? '',
            'trip_code' => $booking->trip_code ?? '',
            'created_at' => $booking->created_at?->toISOString() ?? '',
            'updated_at' => $booking->updated_at?->toISOString() ?? '',
            'cancel_charge' => $this->getCancellationChargeForBooking($booking),
            'user' => [
                'id' => (string) ($booking->user->id ?? ''),
                'name' => $booking->user->name ?? '',
                'email' => $booking->user->email ?? '',
                'phone' => $booking->user->phone ?? '',
                'profile_photo' => $this->getProfilePhotoUrl($booking->user->profile_photo ?? ''),
            ],
            'ride_type' => $booking->rideType ? [
                'id' => (string) $booking->rideType->id,
                'name' => $booking->rideType->name ?? '',
                'base_price' => (string) ($booking->rideType->base_fare ?? $booking->rideType->base_price ?? '0.00'),
                'price_per_km' => (string) ($booking->rideType->per_km_rate ?? $booking->rideType->price_per_km ?? '0.00'),
                'price_per_minute' => (string) ($booking->rideType->per_minute_rate ?? $booking->rideType->price_per_minute ?? '0.00'),
                'waiting_time_limit' => (string) ($booking->rideType->waiting_time_limit ?? '0'),
                'waiting_charge_per_minute' => (string) ($booking->rideType->waiting_charge_per_minute ?? '0.00'),
            ] : [
                'id' => '',
                'name' => '',
                'base_price' => '0.00',
                'price_per_km' => '0.00',
                'price_per_minute' => '0.00',
            ],
        ];

        $etaService = app(ETAService::class);
        $driverLat = $driver->last_latitude;
        $driverLng = $driver->last_longitude;

        if ($driver->currentLocation) {
            $coordinates = $driver->currentLocation->parseLocation();
            $driverLat = $coordinates['latitude'] ?? $driverLat;
            $driverLng = $coordinates['longitude'] ?? $driverLng;
        }

        $distanceToCustomer = '0 km';
        $etaToCustomer = '0 min';

        if ($driverLat && $driverLng && $booking->pickup_latitude && $booking->pickup_longitude) {
            $etaData = $etaService->calculateETA(
                (float) $booking->pickup_latitude,
                (float) $booking->pickup_longitude,
                (int) $booking->city_id,
                (int) $booking->ride_type_id,
                (float) $driverLat,
                (float) $driverLng
            );
            $distanceToCustomer = ($etaData['distance_to_pickup'] ?? 0) . ' km';
            $etaToCustomer = ($etaData['estimated_eta'] ?? 0) . ' min';
        }

        $customer = [
            'customer_name' => $booking->user->name ?? '',
            'customer_photo' => $this->getProfilePhotoUrl($booking->user->profile_photo ?? ''),
            'customer_rating' => $booking->user->rating ?? 0,
            'distance_to_customer' => (int) $distanceToCustomer,
            'eta_to_customer' => $etaToCustomer
        ];

        $invoice = [
            'invoice_number' => 'INV-' . date('Y') . '-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT),
            'booking_code' => $booking->booking_code ?? '',
            'invoice_date' => $booking->completed_at?->format('Y-m-d H:i:s') ?? ($booking->created_at?->format('Y-m-d H:i:s') ?? ''),
            'customer' => $customer,
            'driver' => [
                'name' => $driver->name ?? '',
                'phone' => $driver->phone ?? '',
                'vehicle' => $vehicle ? ($vehicle->model ?? '') : '',
                'license_plate' => $vehicle ? ($vehicle->license_plate ?? '') : '',
            ],
            'payment_details' => [
                'payment_method' => $booking->payment_method ?? '',
                'payment_status' => $booking->payment_status ?? '',
            ],
        ];

        $driverData = [
            'id' => (string) $driver->id,
            'name' => $driver->name ?? '',
            'phone' => $driver->phone ?? '',
            'rating' => (string) ($driverProfile ? ($driverProfile->rating ?? '0') : '0'),
            'vehicle' => [
                'model' => $vehicle ? ($vehicle->model ?? '') : '',
                'number_plate' => $vehicle ? ($vehicle->license_plate ?? '') : '',
            ],
            'is_online' => (string) ($driver->is_online ?? '0'),
        ];

        $pickup = [
            'address' => $booking->pickup_address ?? '',
            'latitude' => (string) ($booking->pickup_latitude ?? ''),
            'longitude' => (string) ($booking->pickup_longitude ?? ''),
        ];

        $dropoff = [
            'address' => $booking->dropoff_address ?? '',
            'latitude' => (string) ($booking->dropoff_latitude ?? ''),
            'longitude' => (string) ($booking->dropoff_longitude ?? ''),
        ];

        $eventType = match ($booking->status) {
            'accepted' => 'driver_accept_ride',
            'arrived' => 'driver_arrived',
            'started' => 'ride_started',
            'completed' => 'ride_completed',
            'cancelled' => 'ride_cancelled',
            default => 'booking_status_changed',
        };

        $response = [
            'type' => '',
            'booking_id' => (string) $booking->id,
            'passenger' => $passenger,
            'trip_details' => $tripDetails,
            'acceptance_timer' => $booking->status === 'accepted' ? 30 : 0,
            'booking' => $bookingData,
            'customer' => $customer,
            'invoice' => $invoice,
            'driver' => $driverData,
            'pickup' => $pickup,
            'dropoff' => $dropoff,
            'event_type' => $eventType,
            'status' => $booking->status ?? '',
            'timestamp' => $booking->updated_at?->toISOString() ?? ($booking->created_at?->toISOString() ?? ''),
        ];

        if ($includeFullDetails) {
            $stringify = static function ($value): string {
                if ($value === null || $value === '') {
                    return '';
                }

                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d H:i:s');
                }

                if (is_array($value) || is_object($value)) {
                    return json_encode($value);
                }

                return (string) $value;
            };

            $detailedBookingData = [
                'id' => (string) $booking->id,
                'booking_code' => $booking->booking_code ?? '',
                'user_id' => $stringify($booking->user_id),
                'passenger_name' => $booking->passenger_name ?? '',
                'booking_contact_id' => $stringify($booking->booking_contact_id),
                'is_other_booking' => $stringify($booking->is_other_booking),
                'driver_id' => $stringify($booking->driver_id),
                'city_id' => $stringify($booking->city_id),
                'ride_type_id' => $stringify($booking->ride_type_id),
                'pickup_zone_id' => $stringify($booking->pickup_zone_id),
                'dropoff_zone_id' => $stringify($booking->dropoff_zone_id),
                'pickup_location' => $booking->pickup_location ?? '',
                'pickup_latitude' => $stringify($booking->pickup_latitude),
                'pickup_longitude' => $stringify($booking->pickup_longitude),
                'dropoff_location' => $booking->dropoff_location ?? '',
                'dropoff_latitude' => $stringify($booking->dropoff_latitude),
                'dropoff_longitude' => $stringify($booking->dropoff_longitude),
                'pickup_address' => $booking->pickup_address ?? '',
                'dropoff_address' => $booking->dropoff_address ?? '',
                'status' => $booking->status ?? '',
                'is_confirm' => $stringify($booking->is_confirm),
                'payment_method' => $booking->payment_method ?? '',
                'payment_status' => $booking->payment_status ?? '',
                'estimated_distance' => $stringify($booking->estimated_distance),
                'estimated_duration' => $stringify($booking->estimated_duration),
                'distance' => $stringify($booking->distance),
                'duration' => $stringify($booking->duration),
                'actual_distance' => $stringify($booking->actual_distance),
                'actual_duration' => $stringify($booking->actual_duration),
                'base_fare' => $stringify($booking->base_fare),
                'distance_fare' => $stringify($booking->distance_fare),
                'time_fare' => $stringify($booking->time_fare),
                'waiting_charge' => $stringify($booking->waiting_charge),
                'cancellation_charge' => $stringify($booking->cancellation_charge),
                'night_charge' => $stringify($booking->night_charge),
                'surge_multiplier' => $stringify($booking->surge_multiplier),
                'surge_amount' => $stringify($booking->surge_amount),
                'subtotal' => $stringify($booking->subtotal),
                'tax_rate' => $stringify($booking->tax_rate),
                'tax_amount' => $stringify($booking->tax_amount),
                'total_amount' => $stringify($booking->total_amount),
                'estimated_fare' => $stringify($booking->estimated_fare),
                'final_fare' => $stringify($booking->final_fare),
                'admin_commission_rate' => $stringify($booking->admin_commission_rate),
                'admin_commission' => $stringify($booking->admin_commission),
                'platform_commission' => $stringify($booking->platform_commission),
                'driver_amount' => $stringify($booking->driver_amount),
                'promo_code' => $booking->promo_code ?? '',
                'promo_usage_id' => $stringify($booking->promo_usage_id),
                'discount_amount' => $stringify($booking->discount_amount),
                'debt_amount' => $stringify($booking->debt_amount),
                'wallet_amount' => $stringify($booking->wallet_amount),
                'online_paid_amount' => $stringify($booking->online_paid_amount),
                'cash_amount' => $stringify($booking->cash_amount),
                'scheduled_at' => $booking->scheduled_at?->toISOString() ?? '',
                'started_at' => $booking->started_at?->toISOString() ?? '',
                'driver_arrival_time' => $booking->driver_arrival_time?->toISOString() ?? '',
                'pickup_time' => $booking->pickup_time?->toISOString() ?? '',
                'dropoff_time' => $booking->dropoff_time?->toISOString() ?? '',
                'completed_at' => $booking->completed_at?->toISOString() ?? '',
                'cancelled_at' => $booking->cancelled_at?->toISOString() ?? '',
                'cancellation_reason' => $booking->cancellation_reason ?? '',
                'cancelled_by_type' => $booking->cancelled_by_type ?? '',
                'cancelled_by_id' => $stringify($booking->cancelled_by_id),
                'user_rating' => $stringify($booking->user_rating),
                'user_review' => $booking->user_review ?? '',
                'user_comment' => $booking->user_comment ?? '',
                'driver_rating' => $stringify($booking->driver_rating),
                'driver_review' => $booking->driver_review ?? '',
                'driver_comment' => $booking->driver_comment ?? '',
                'waiting_time' => $stringify($booking->waiting_time),
                'otp' => $booking->otp ?? '',
                'trip_code' => $booking->trip_code ?? '',
                'meta_data' => $stringify($booking->meta_data ?? ''),
                'created_at' => $booking->created_at?->toISOString() ?? '',
                'updated_at' => $booking->updated_at?->toISOString() ?? '',
                'deleted_at' => $booking->deleted_at?->toISOString() ?? '',
                'wallet_transaction_id' => $stringify($booking->wallet_transaction_id),
                'user' => [
                    'id' => $stringify($booking->user->id ?? ''),
                    'name' => $booking->user->name ?? '',
                    'email' => $booking->user->email ?? '',
                    'total_earnings_this_week' => $stringify($booking->user->total_earnings_this_week ?? ''),
                    'max_withdrawal_limit' => $stringify($booking->user->max_withdrawal_limit ?? ''),
                    'scheduled_payout_date' => $booking->user?->scheduled_payout_date?->toISOString() ?? '',
                    'google_id' => $stringify($booking->user->google_id ?? ''),
                    'apple_id' => $stringify($booking->user->apple_id ?? ''),
                    'gender' => $booking->user->gender ?? '',
                    'phone' => $booking->user->phone ?? '',
                    'address' => $booking->user->address ?? '',
                    'country_code' => $booking->user->country_code ?? '',
                    'date_of_birth' => $booking->user?->date_of_birth instanceof \DateTimeInterface
                        ? $booking->user->date_of_birth->toDateString()
                        : ($booking->user?->date_of_birth ?? ''),
                    'password_reset_token' => $stringify($booking->user->password_reset_token ?? ''),
                    'password_reset_expires_at' => $booking->user?->password_reset_expires_at?->toISOString() ?? '',
                    'role_id' => $stringify($booking->user->role_id ?? ''),
                    'profile_photo' => $this->getProfilePhotoUrl($booking->user->profile_photo ?? ''),
                    'login_device' => $booking->user->login_device ?? '',
                    'token_expires_at' => $booking->user?->token_expires_at?->toISOString() ?? '',
                    'is_online' => $stringify($booking->user->is_online ?? ''),
                    'is_verified' => $stringify($booking->user->is_verified ?? ''),
                    'verified_at' => $booking->user?->verified_at?->toISOString() ?? '',
                    'last_location_at' => $booking->user?->last_location_at?->toISOString() ?? '',
                    'last_latitude' => $stringify($booking->user->last_latitude ?? ''),
                    'last_longitude' => $stringify($booking->user->last_longitude ?? ''),
                    'select_latitude' => $stringify($booking->user->select_latitude ?? ''),
                    'select_longitude' => $stringify($booking->user->select_longitude ?? ''),
                    'email_verified_at' => $booking->user?->email_verified_at?->toISOString() ?? '',
                    'phone_verified_at' => $booking->user?->phone_verified_at?->toISOString() ?? '',
                    'status' => $booking->user->status ?? '',
                    'is_register' => $stringify($booking->user->is_register ?? ''),
                    'step_0' => $stringify($booking->user->step_0 ?? ''),
                    'step_1' => $stringify($booking->user->step_1 ?? ''),
                    'step_2' => $stringify($booking->user->step_2 ?? ''),
                    'step_3' => $stringify($booking->user->step_3 ?? ''),
                    'referral_code' => $booking->user->referral_code ?? '',
                    'referred_by' => $stringify($booking->user->referred_by ?? ''),
                    'meta_data' => $stringify($booking->user->meta_data ?? ''),
                    'created_at' => $booking->user?->created_at?->toISOString() ?? '',
                    'updated_at' => $booking->user?->updated_at?->toISOString() ?? '',
                    'deleted_at' => $booking->user?->deleted_at?->toISOString() ?? '',
                    'current_booking_id' => $stringify($booking->user?->current_booking_id ?? ''),
                ],
                'ride_type' => $booking->rideType ? [
                    'id' => $stringify($booking->rideType->id),
                    'name' => $booking->rideType->name ?? '',
                    'code' => $booking->rideType->code ?? '',
                    'description' => $booking->rideType->description ?? '',
                    'icon' => $booking->rideType->icon ?? '',
                    'capacity' => $stringify($booking->rideType->capacity ?? ''),
                    'status' => $stringify($booking->rideType->status ?? ''),
                    'order' => $stringify($booking->rideType->order ?? ''),
                    'base_distance' => $stringify($booking->rideType->base_distance ?? ''),
                    'base_price' => $stringify($booking->rideType->base_price ?? ''),
                    'price_per_km' => $stringify($booking->rideType->price_per_km ?? ''),
                    'price_per_minute' => $stringify($booking->rideType->price_per_minute ?? ''),
                    'minimum_fare' => $stringify($booking->rideType->minimum_fare ?? ''),
                    'cancellation_charge' => $stringify($booking->rideType->cancellation_charge ?? ''),
                    'waiting_charge_per_minute' => $stringify($booking->rideType->waiting_charge_per_minute ?? ''),
                    'waiting_time_limit' => $stringify($booking->rideType->waiting_time_limit ?? ''),
                    'commission_rate' => $stringify($booking->rideType->commission_rate ?? ''),
                    'driver_requirements' => $booking->rideType->driver_requirements ?? null,
                    'vehicle_requirements' => $booking->rideType->vehicle_requirements ?? null,
                    'meta_data' => $stringify($booking->rideType->meta_data ?? ''),
                    'created_at' => $booking->rideType->created_at?->toISOString() ?? '',
                    'updated_at' => $booking->rideType->updated_at?->toISOString() ?? '',
                    'deleted_at' => $booking->rideType->deleted_at?->toISOString() ?? '',
                ] : [
                    'id' => '',
                    'name' => '',
                    'code' => '',
                    'description' => '',
                    'icon' => '',
                    'capacity' => '',
                    'status' => '',
                    'order' => '',
                    'base_distance' => '',
                    'base_price' => '',
                    'price_per_km' => '',
                    'price_per_minute' => '',
                    'minimum_fare' => '',
                    'cancellation_charge' => '',
                    'waiting_charge_per_minute' => '',
                    'waiting_time_limit' => '',
                    'commission_rate' => '',
                    'driver_requirements' => null,
                    'vehicle_requirements' => null,
                    'meta_data' => '',
                    'created_at' => '',
                    'updated_at' => '',
                    'deleted_at' => '',
                ],
            ];

            $fareDetails = [
                'base_fare' => $stringify($booking->base_fare),
                'distance_fare' => $stringify($booking->distance_fare),
                'time_fare' => $stringify($booking->time_fare),
                'waiting_charge' => $stringify($booking->waiting_charge),
                'night_charge' => $stringify($booking->night_charge),
                'surge_amount' => $stringify($booking->surge_amount),
                'subtotal' => $stringify($booking->subtotal),
                'discount_amount' => $stringify($booking->discount_amount),
                'debt_amount' => $stringify($booking->debt_amount),
                'tax_amount' => $stringify($booking->tax_amount),
                'total_amount' => $stringify($booking->total_amount),
                'admin_commission' => $stringify($booking->admin_commission),
                'driver_amount' => $stringify($booking->driver_amount),
            ];

            $invoiceDetailed = $invoice;
            $invoiceDetailed['booking_id'] = (string) $booking->id;
            $invoiceDetailed['trip_details'] = [
                'pickup_address' => $booking->pickup_address ?? '',
                'dropoff_address' => $booking->dropoff_address ?? '',
                'distance' => $booking->actual_distance ? $stringify($booking->actual_distance) . ' km' : '',
                'duration' => $booking->actual_duration ? $stringify($booking->actual_duration) . ' minutes' : '',
                'started_at' => $booking->started_at?->format('Y-m-d H:i:s') ?? '',
                'completed_at' => $booking->completed_at?->format('Y-m-d H:i:s') ?? '',
            ];
            $invoiceDetailed['fare_breakdown'] = [
                'base_fare' => $stringify($booking->base_fare),
                'distance_fare' => $stringify($booking->distance_fare),
                'time_fare' => $stringify($booking->time_fare),
                'waiting_charge' => $stringify($booking->waiting_charge),
                'night_charge' => $stringify($booking->night_charge),
                'surge_amount' => $stringify($booking->surge_amount),
                'subtotal' => $stringify($booking->subtotal),
                'tax_amount' => $stringify($booking->tax_amount),
                'total_amount' => $stringify($booking->total_amount),
            ];
            $invoiceDetailed['payment_details'] = [
                'payment_method' => $booking->payment_method ?? '',
                'payment_status' => $booking->payment_status ?? '',
                'driver_amount' => $stringify($booking->driver_amount),
                'platform_commission' => $stringify($booking->admin_commission),
                'driver_commission_rate' => $booking->admin_commission_rate !== null
                    ? (string) (100 - (float) $booking->admin_commission_rate) . '%'
                    : '',
                'platform_commission_rate' => $booking->admin_commission_rate !== null
                    ? (string) (float) $booking->admin_commission_rate . '%'
                    : '',
            ];

            $response['booking'] = $detailedBookingData;
            $response['fare'] = $fareDetails;
            $response['invoice'] = $invoiceDetailed;
        }

        return $response;
    }

    private function driverNeedsRegistration(User $user): bool
    {
        $requiredFields = ['phone'];

        foreach ($requiredFields as $field) {
            if (empty($user->$field)) {
                return true;
            }
        }

        return false;
    }

    private function getAvailablePaymentMethods(): array
    {
        $availablePayments = [];

        if (SystemConfiguration::getValue('razorpay_enabled', false)) {
            $availablePayments[] = 'razorpay';
        }

        if (SystemConfiguration::getValue('stripe_enabled', false)) {
            $availablePayments[] = 'stripe';
        }

        if (SystemConfiguration::getValue('paytm_enabled', false)) {
            $availablePayments[] = 'paytm';
        }

        if (SystemConfiguration::getValue('cod_enabled', true)) {
            $availablePayments[] = 'cod';
        }

        return $availablePayments;
    }

    /**
     * Get weekly goal data for driver
     *
     * @param User $user
     * @return array|null
     */
    private function getWeeklyGoal(User $user): ?array
    {
        try {
            $now = Carbon::now();
            $weekStart = $now->copy()->startOfWeek();
            $weekEnd = $now->copy()->endOfWeek();

            // Calculate current week earnings from completed bookings
            $currentWeekEarnings = Booking::where('driver_id', $user->id)
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$weekStart, $weekEnd])
                ->sum('driver_amount');

            // Try to find active weekly incentive
            $weeklyIncentive = DriverIncentive::forDriver($user->id)
                ->active()
                ->where('type', 'weekly')
                ->where(function ($query) use ($weekStart, $weekEnd) {
                    $query
                        ->whereBetween('start_time', [$weekStart, $weekEnd])
                        ->orWhereBetween('end_time', [$weekStart, $weekEnd])
                        ->orWhere(function ($q) use ($weekStart, $weekEnd) {
                            $q
                                ->where('start_time', '<=', $weekStart)
                                ->where('end_time', '>=', $weekEnd);
                        });
                })
                ->where('is_active', true)
                ->first();

            // If no specific weekly incentive, check for any active incentive that covers this week
            if (!$weeklyIncentive) {
                $weeklyIncentive = DriverIncentive::forDriver($user->id)
                    ->active()
                    ->where(function ($query) use ($weekStart, $weekEnd) {
                        $query
                            ->whereBetween('start_time', [$weekStart, $weekEnd])
                            ->orWhereBetween('end_time', [$weekStart, $weekEnd])
                            ->orWhere(function ($q) use ($weekStart, $weekEnd) {
                                $q
                                    ->where('start_time', '<=', $weekStart)
                                    ->where('end_time', '>=', $weekEnd);
                            });
                    })
                    ->where('is_active', true)
                    ->first();
            }

            // If incentive found, use its data
            if ($weeklyIncentive && $weeklyIncentive->isLive()) {
                $criteria = $weeklyIncentive->criteria ?? [];
                $targetAmount = $criteria['target_amount'] ?? $criteria['target'] ?? 0;
                $bonusAmount = $weeklyIncentive->reward_amount ?? 0;
                $endTime = $weeklyIncentive->end_time;

                // If target is in rides count, calculate earnings from target rides
                if (isset($criteria['target']) && !isset($criteria['target_amount'])) {
                    // This is a ride count target, not earnings target
                    // Calculate average earnings per ride to estimate target amount
                    $completedRides = Booking::where('driver_id', $user->id)
                        ->where('status', 'completed')
                        ->whereBetween('completed_at', [$weekStart, $weekEnd])
                        ->count();

                    $avgEarningPerRide = $completedRides > 0
                        ? ($currentWeekEarnings / $completedRides)
                        : 0;

                    $targetAmount = ($criteria['target'] ?? 0) * $avgEarningPerRide;
                }
            } else {
                // Default weekly goal if no incentive found
                // You can customize these defaults or return null
                $targetAmount = 150;  // Default target
                $bonusAmount = 20;  // Default bonus
                $endTime = $weekEnd->copy()->endOfDay();
            }

            // Calculate progress
            $targetAmount = (float) $targetAmount;
            $currentEarnings = (float) $currentWeekEarnings;
            $progressPercentage = $targetAmount > 0
                ? min(100, round(($currentEarnings / $targetAmount) * 100, 2))
                : 0;
            // Only mark as completed if: target exists, driver has earnings, and earnings meet target
            $isCompleted = $targetAmount > 0 && $currentEarnings > 0 && $currentEarnings >= $targetAmount;

            // Format deadline
            $deadline = null;
            if (isset($endTime)) {
                $endTimeCarbon = $endTime instanceof Carbon ? $endTime : Carbon::parse($endTime);
                $daysRemaining = $now->diffInDays($endTimeCarbon, false);

                if ($daysRemaining < 0) {
                    $deadline = 'Expired';
                } elseif ($daysRemaining == 0) {
                    $deadline = 'Ends Today at ' . $endTimeCarbon->format('g:i A');
                } elseif ($daysRemaining == 1) {
                    $deadline = 'Ends Tomorrow at ' . $endTimeCarbon->format('g:i A');
                } else {
                    $deadline = 'Ends ' . $endTimeCarbon->format('M d') . ' at ' . $endTimeCarbon->format('g:i A');
                }
            } else {
                $deadline = 'Ends ' . $weekEnd->format('M d') . ' at 11:59 PM';
            }

            return [
                'target_amount' => (string) number_format($targetAmount, 2, '.', ''),
                'current_earnings' => (string) number_format($currentEarnings, 2, '.', ''),
                'bonus_amount' => (string) number_format($bonusAmount, 2, '.', ''),
                'progress_percentage' => (string) $progressPercentage,
                'is_completed' => (string) ($isCompleted ? 1 : 0),
                'deadline' => $deadline,
                'week_start' => $weekStart->format('Y-m-d'),
                'week_end' => $weekEnd->format('Y-m-d'),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get Night Riders Bonus data for driver
     *
     * @param User $user
     * @return array|null
     */
    private function getNightRidersBonus(User $user): ?array
    {
        try {
            $now = Carbon::now();

            // Night time is between 10 PM (22:00) and 4 AM (04:00)
            // We need to check rides completed during this time window
            $nightStartHour = 22;  // 10 PM
            $nightEndHour = 4;  // 4 AM

            // Get all completed bookings
            $allCompletedBookings = Booking::where('driver_id', $user->id)
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->get();

            // Filter night rides (completed between 10 PM - 4 AM)
            $nightRides = $allCompletedBookings->filter(function ($booking) use ($nightStartHour, $nightEndHour) {
                $completedAt = Carbon::parse($booking->completed_at);
                $hour = (int) $completedAt->format('H');

                // Check if ride was completed between 10 PM and 4 AM
                return $hour >= $nightStartHour || $hour < $nightEndHour;
            });

            $currentNightRidesCount = $nightRides->count();

            // Try to find active "Night Riders Bonus" incentive
            $nightRidersIncentive = DriverIncentive::forDriver($user->id)
                ->active()
                ->where(function ($query) {
                    $query
                        ->where('title', 'like', '%Night Riders%')
                        ->orWhere('title', 'like', '%Night Rider%')
                        ->orWhere('description', 'like', '%10 PM%')
                        ->orWhere('description', 'like', '%4 AM%');
                })
                ->where('is_active', true)
                ->where(function ($query) use ($now) {
                    $query
                        ->where('start_time', '<=', $now)
                        ->where('end_time', '>=', $now);
                })
                ->first();

            // If incentive found, use its data
            if ($nightRidersIncentive && $nightRidersIncentive->isLive()) {
                $criteria = $nightRidersIncentive->criteria ?? [];
                $targetRides = $criteria['target'] ?? $criteria['target_rides'] ?? 5;
                $bonusAmount = $nightRidersIncentive->reward_amount ?? 250;

                // Check time slots from incentive
                $timeSlots = $nightRidersIncentive->time_slots ?? [];
                if (!empty($timeSlots)) {
                    // Re-filter night rides based on incentive time slots
                    $nightRides = $allCompletedBookings->filter(function ($booking) use ($timeSlots) {
                        $completedAt = Carbon::parse($booking->completed_at);

                        foreach ($timeSlots as $slot) {
                            $startTime = $slot['start'] ?? null;
                            $endTime = $slot['end'] ?? null;

                            if ($startTime && $endTime) {
                                try {
                                    $start = Carbon::parse($startTime);
                                    $end = Carbon::parse($endTime);

                                    // Handle time slots that span midnight (e.g., 22:00 to 04:00)
                                    if (($start->greaterThan($end)) || ($start->format('H') >= 22 && $end->format('H') < 4)) {
                                        // Time slot spans midnight
                                        if ($completedAt->format('H') >= $start->format('H') || $completedAt->format('H') < $end->format('H')) {
                                            return true;
                                        }
                                    } else {
                                        // Normal time slot
                                        if ($completedAt->between($start, $end)) {
                                            return true;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    continue;
                                }
                            }
                        }

                        return false;
                    });

                    $currentNightRidesCount = $nightRides->count();
                }
            } else {
                // Default Night Riders Bonus if no incentive found
                $targetRides = 5;
                $bonusAmount = 250;  // ₹250
            }

            // Calculate progress
            $targetRides = (int) $targetRides;
            $currentRides = (int) $currentNightRidesCount;
            $progressPercentage = $targetRides > 0
                ? min(100, round(($currentRides / $targetRides) * 100, 2))
                : 0;
            $isCompleted = $currentRides >= $targetRides;

            // Get remaining rides needed
            $remainingRides = max(0, $targetRides - $currentRides);

            return [
                'title' => 'Night Riders Bonus',
                'description' => "Earn ₹{$bonusAmount} extra when you complete {$targetRides} rides between 10 PM - 4 AM.",
                'target_rides' => (string) $targetRides,
                'current_rides' => (string) $currentRides,
                'remaining_rides' => (string) $remainingRides,
                'bonus_amount' => (string) number_format($bonusAmount, 2, '.', ''),
                'progress_percentage' => (string) $progressPercentage,
                'is_completed' => (string) ($isCompleted ? 1 : 0),
                'time_slot' => '10 PM - 4 AM',
                'time_slot_start' => '22:00',
                'time_slot_end' => '04:00',
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Credit weekly goal bonus to driver wallet if completed and not yet credited
     *
     * @param User $user
     * @param array $weeklyGoal
     * @return void
     */
    private function creditWeeklyGoalBonus(User $user, array $weeklyGoal): void
    {
        try {
            $bonusAmount = (float) ($weeklyGoal['bonus_amount'] ?? 0);

            if ($bonusAmount <= 0) {
                return;
            }

            // Safety check: Ensure driver has actual earnings before crediting bonus
            $currentEarnings = (float) ($weeklyGoal['current_earnings'] ?? 0);
            $targetAmount = (float) ($weeklyGoal['target_amount'] ?? 0);

            if ($currentEarnings <= 0 || $targetAmount <= 0 || $currentEarnings < $targetAmount) {
                
                return;
            }

            $metaData = $user->meta_data ?? [];
            $weekStart = $weeklyGoal['week_start'] ?? null;
            $weekEnd = $weeklyGoal['week_end'] ?? null;

            // Create a unique key for this week's bonus
            $bonusKey = "weekly_goal_bonus_{$weekStart}_{$weekEnd}";

            // Check if bonus already credited for this week
            if (isset($metaData[$bonusKey]) && $metaData[$bonusKey]['credited'] === true) {

                return;
            }

            // Credit bonus to wallet
            $walletService = app(\App\Services\WalletService::class);
            $wallet = $walletService->ensureWallet($user);

            if (!$wallet->isActive()) {

                return;
            }

            $walletTransaction = $wallet->credit(
                $bonusAmount,
                \App\Models\WalletTransaction::TYPE_INCENTIVE_REWARD,
                "Weekly goal bonus: Earned ₹{$bonusAmount} for achieving weekly earnings target",
                [
                    'driver_id' => $user->id,
                    'bonus_type' => 'weekly_goal',
                    'week_start' => $weekStart,
                    'week_end' => $weekEnd,
                    'target_amount' => $weeklyGoal['target_amount'] ?? 0,
                    'current_earnings' => $weeklyGoal['current_earnings'] ?? 0,
                    'credited_at' => Carbon::now()->toDateTimeString(),
                ]
            );

            // Mark as credited in user meta_data
            $metaData[$bonusKey] = [
                'credited' => true,
                'credited_at' => Carbon::now()->toDateTimeString(),
                'amount' => $bonusAmount,
                'wallet_transaction_id' => $walletTransaction->id,
            ];

            $user->meta_data = $metaData;
            $user->save();

            
        } catch (\Exception $e) {
        }
    }

    /**
     * Credit night riders bonus to driver wallet if completed and not yet credited
     *
     * @param User $user
     * @param array $nightRidersBonus
     * @return void
     */
    private function creditNightRidersBonus(User $user, array $nightRidersBonus): void
    {
        try {
            $bonusAmount = (float) ($nightRidersBonus['bonus_amount'] ?? 0);

            if ($bonusAmount <= 0) {
                return;
            }

            $metaData = $user->meta_data ?? [];

            // Create a unique key for night riders bonus
            // Use current month to track monthly bonuses
            $currentMonth = Carbon::now()->format('Y-m');
            $bonusKey = "night_riders_bonus_{$currentMonth}";

            // Check if bonus already credited for this month
            if (isset($metaData[$bonusKey]) && $metaData[$bonusKey]['credited'] === true) {

                return;
            }

            // Credit bonus to wallet
            $walletService = app(\App\Services\WalletService::class);
            $wallet = $walletService->ensureWallet($user);

            if (!$wallet->isActive()) {

                return;
            }

            $targetRides = $nightRidersBonus['target_rides'] ?? 0;
            $currentRides = $nightRidersBonus['current_rides'] ?? 0;

            $walletTransaction = $wallet->credit(
                $bonusAmount,
                \App\Models\WalletTransaction::TYPE_INCENTIVE_REWARD,
                "Night Riders Bonus: Earned ₹{$bonusAmount} for completing {$targetRides} night rides",
                [
                    'driver_id' => $user->id,
                    'bonus_type' => 'night_riders',
                    'target_rides' => $targetRides,
                    'current_rides' => $currentRides,
                    'time_slot' => $nightRidersBonus['time_slot'] ?? '10 PM - 4 AM',
                    'credited_at' => Carbon::now()->toDateTimeString(),
                ]
            );

            // Mark as credited in user meta_data
            $metaData[$bonusKey] = [
                'credited' => true,
                'credited_at' => Carbon::now()->toDateTimeString(),
                'amount' => $bonusAmount,
                'wallet_transaction_id' => $walletTransaction->id,
                'target_rides' => $targetRides,
                'current_rides' => $currentRides,
            ];

            $user->meta_data = $metaData;
            $user->save();

            
        } catch (\Exception $e) {
        }
    }

    /**
     * Auto-approve all documents and vehicle registration after step 3 completion
     */
    protected function autoApproveDriverRegistration(User $user, ?Vehicle $vehicle): void
    {
        try {
            DB::beginTransaction();

            // Approve all pending driver documents (using model to trigger events)
            $driverDocuments = $user->documents()->where('status', 'pending')->get();
            $driverDocumentsCount = $driverDocuments->count();
            foreach ($driverDocuments as $document) {
                $document->update([
                    'status' => 'approved',
                    'verified_at' => now(),
                    'verified_by' => null, // Auto-approved, no admin user
                    'rejection_reason' => null,
                ]);
            }

            // Approve all pending vehicle documents (using model to trigger events)
            $vehicleDocumentsCount = 0;
            if ($vehicle) {
                $vehicleDocuments = $vehicle->documents()->where('status', 'pending')->get();
                $vehicleDocumentsCount = $vehicleDocuments->count();
                foreach ($vehicleDocuments as $document) {
                    $document->update([
                        'status' => 'approved',
                        'verified_at' => now(),
                        'verified_by' => null, // Auto-approved, no admin user
                        'rejection_reason' => null,
                    ]);
                }
            }

            // Approve vehicle registration in driver profile
            $driverProfile = $user->driverProfile;
            if ($driverProfile) {
                $metaData = $driverProfile->meta_data ?? [];
                $metaData['vehicle_registration_status'] = 'approved';
                $metaData['vehicle_registration_approved'] = true;
                $metaData['vehicle_registration_rejection_reason'] = null;
                $driverProfile->update(['meta_data' => $metaData]);
            } else {
                // Create driver profile if it doesn't exist
                $driverProfile = DriverProfile::create([
                    'driver_id' => $user->id,
                    'meta_data' => [
                        'vehicle_registration_status' => 'approved',
                        'vehicle_registration_approved' => true,
                    ],
                ]);
            }

            DB::commit();

            // Refresh user to get updated relationships
            $user->refresh();
            if ($vehicle) {
                $vehicle->refresh();
            }

            // Trigger verification status update (this will be called by Document model events too, but calling explicitly to ensure it runs)
            $user->updateVerificationStatus();

            
        } catch (\Exception $e) {
            DB::rollBack();
        }
    }

    /**
     * Check if driver is autogenerated
     * Autogenerated drivers have email pattern: {cityname}driver@etaxi.com
     *
     * @param User $driver
     * @return bool
     */
    private function isAutogeneratedDriver(User $driver): bool
    {
        if (!$driver->email) {
            return false;
        }

        // Check if email matches pattern: {cityname}driver@etaxi.com
        // Pattern: any characters followed by "driver@etaxi.com"
        $pattern = '/^.+driver@etaxi\.com$/i';

        if (preg_match($pattern, $driver->email)) {
            return true;
        }

        return false;
    }
}
