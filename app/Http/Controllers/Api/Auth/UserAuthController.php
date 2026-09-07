<?php

namespace App\Http\Controllers\Api\Auth;

use App\Models\Booking;
use App\Models\CancellationFeeDispute;
use App\Models\Chat;
use App\Models\DriverDocument;
use App\Models\DriverRating;
use App\Models\IssueReport;
use App\Models\RefundRequest;
use App\Models\SupportChat;
use App\Models\SystemConfiguration;
use App\Models\User;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UserAuthController extends BaseAuthController
{
    public function checkEmail(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
                'role_id' => ['required', 'integer', 'in:3,2'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $email = $request->input('email');
        $isDemo = str_ends_with($email, '@etaxi.com') ? 1 : 0;
        $roleId = $request->input('role_id');
        $emailExists = User::where('email', $email)
            ->where('role_id', $roleId)
            ->where('email', '!like', '%@etaxi.com%')
            ->first();
        return response()->json([
            'success' => true,
            'message' => $emailExists ? 'Email already registered' : 'Email not registered',
            'data' => [
                'is_register' => $emailExists ? 1 : 0,
                'login_device' => $emailExists?->login_device,
                'is_demo' => $isDemo,
            ],
        ], 200);
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
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $phone = $request->input('phone');
        $countryCode = $request->input('country_code', '+91');
        $deviceToken = $request->input('device_token');
        $signature = $request->input('signature');

        $otpRecord = $this->createOtpRecord($phone, 'user_login', $countryCode);

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

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully',
            'expires_in' => '1 minutes',
            'otp' => $otpRecord->otp,  // Remove this in production
            'data' => $responseData,
        ]);
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
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $phone = $request->input('phone');
        $countryCode = $request->input('country_code', '+91');
        $deviceToken = $request->input('device_token') ?? '';

        $user = User::firstOrCreate(
            [
                'phone' => $phone,
                'role_id' => 3,  // User role
            ],
            [
                'status' => 'active',
                'country_code' => $countryCode,
                'phone_verified_at' => now(),
            ]
        );

        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been blocked. Please contact support.',
            ], 422);
        }

        $authData = $this->createAuthToken($user, $deviceToken, 'phone');

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $authData['token'],
            'user' => $authData['user'],
        ]);
    }

    private function getOtpAttemptCacheKey(string $phone, string $countryCode): string
    {
        return 'user_login_otp_attempts:' . $countryCode . ':' . $phone;
    }

    private function getOtpBlockCacheKey(string $phone, string $countryCode): string
    {
        return 'user_login_otp_block:' . $countryCode . ':' . $phone;
    }

    public function register(Request $request): JsonResponse
    {
        try {
            $authenticatedUser = null;
            $bearerToken = $request->bearerToken();

            if ($bearerToken) {
                $authenticatedUser = $this->validateBearerToken($bearerToken);
            }

            $request->validate([
                'full_name' => ['required', 'string', 'max:255', 'min:2'],
                'email' => [
                    'nullable',
                    'email',
                    'max:255',
                    function ($attribute, $value, $fail) use ($authenticatedUser) {
                        if (!empty($value)) {
                            $existingUser = \App\Models\User::where('email', $value)->where('role_id', 3)->first();

                            if ($authenticatedUser && $existingUser && $authenticatedUser->id === $existingUser->id) {
                                return;
                            }

                            if ($existingUser && $this->userNeedsRegistration($existingUser)) {
                                return;
                            }

                            if ($existingUser) {
                                $fail('This email address is already registered.');
                            }
                        }
                    }
                ],
                'phone' => [
                    'required',
                    'string',
                    'regex:/^[0-9]{10}$/',
                    function ($attribute, $value, $fail) use ($authenticatedUser) {
                        $existingUser = \App\Models\User::where('phone', $value)->where('role_id', 3)->first();

                        if ($authenticatedUser && $existingUser && $authenticatedUser->id === $existingUser->id) {
                            return;
                        }

                        if ($existingUser && $this->userNeedsRegistration($existingUser)) {
                            return;
                        }

                        if ($existingUser) {
                            $fail('This phone number is already registered.');
                        }
                    }
                ],
                'country_code' => ['nullable', 'string', 'regex:/^\+[1-9]\d{0,3}$/', 'max:5'],
                'password' => ['nullable', 'string', 'min:6'],
                'gender' => ['required'],
                'referral_code' => [
                    'nullable',
                    'string',
                    function ($attribute, $value, $fail) {
                        if (!empty($value)) {
                            $exists = \App\Models\User::where('referral_code', $value)
                                ->where('role_id', 3)
                                ->exists();
                            if (!$exists) {
                                $fail('The referral code is invalid.');
                            }
                        }
                    }
                ],
                'device_token' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $e) {
            $firstError = collect($e->errors())->flatten()->first();

            return response()->json([
                'success' => false,
                'message' => $firstError ?? 'Validation failed',
            ], 422);
        }

        try {
            $isUpdate = false;
            $user = null;
            $message = '';

            if ($authenticatedUser) {
                $user = $authenticatedUser;
                $isUpdate = true;

                $updateData = [
                    'name' => $request->full_name,
                    'phone' => $request->phone,
                    'country_code' => $request->input('country_code', '+91'),
                    'gender' => $request->gender,
                    'is_register' => 1,  // Mark as registered since all required fields are provided
                ];

                if (!empty($request->email)) {
                    $updateData['email'] = $request->email;
                }

                if (!empty($request->password)) {
                    $updateData['password'] = \Illuminate\Support\Facades\Hash::make($request->password);
                }

                if (!empty($request->device_token)) {
                    $updateData['device_token'] = $request->device_token;
                }

                $user->update($updateData);

                if (empty($user->referral_code)) {
                    $user->generateReferralCode();
                }

                $user = $user->fresh();

                $message = 'Profile updated successfully';
            } else {
                $existingUser = null;
                if (!empty($request->email)) {
                    $existingUser = User::where('email', $request->email)
                        ->where('role_id', 3)
                        ->first();
                }

                if (!$existingUser) {
                    $existingUser = User::where('phone', $request->phone)
                        ->where('role_id', 3)
                        ->first();
                }

                if ($existingUser) {

                    $user = $existingUser;
                    $isUpdate = true;

                    $updateData = [
                        'name' => $request->full_name,
                        'phone' => $request->phone,
                        'country_code' => $request->input('country_code', '+91'),
                        'gender' => $request->gender,
                        'is_register' => 1,  // Mark as registered since all required fields are provided
                    ];

                    if (!empty($request->email)) {
                        $updateData['email'] = $request->email;
                    }

                    if (!empty($request->password)) {
                        $updateData['password'] = \Illuminate\Support\Facades\Hash::make($request->password);
                    }

                    if (!empty($request->device_token)) {
                        $updateData['device_token'] = $request->device_token;
                    }

                    $user->update($updateData);


                    if (empty($user->referral_code)) {

                        $user->generateReferralCode();
                    } else {
                    }

                    $user = $user->fresh();



                    $message = 'Profile updated successfully';
                } else {
                    $user = User::create([
                        'name' => $request->full_name,
                        'phone' => $request->phone,
                        'country_code' => $request->input('country_code', '+91'),
                        'email' => $request->email,
                        'gender' => $request->gender,
                        'role_id' => 3,  // User role
                        'status' => 'active',
                        'phone_verified_at' => now(),
                        'device_token' => $request->device_token ?? '',
                        'password' => $request->password ? \Illuminate\Support\Facades\Hash::make($request->password) : null,
                        'is_register' => 1,  // Mark as registered since all required fields are provided
                    ]);


                    if (empty($user->referral_code)) {

                        $user->generateReferralCode();
                    }

                    $user = $user->fresh();



                    $message = 'Registration successful';
                }
            }

            if (!empty($request->referral_code)) {
                if (!empty($user->referral_code) && $user->referral_code === $request->referral_code) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You cannot use your own referral code.',
                    ], 422);
                }
                $referrer = User::where('referral_code', $request->referral_code)
                    ->where('role_id', 3)
                    ->first();
                if ($referrer) {
                    $user->update(['referred_by' => $referrer->id]);

                    $this->processReferralRewards($user, $referrer);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid referral code',
                    ], 422);
                }
            }

            $responseData = [
                'success' => true,
                'message' => $message,
                'user' => $this->getUserResponse($user),
            ];

            if (!$authenticatedUser) {
                $authData = $this->createAuthToken($user, $request->device_token ?? '', 'phone');
                $responseData['token'] = $authData['token'];
            }

            return response()->json($responseData);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23000) {  // MySQL duplicate entry error
                if (str_contains($e->getMessage(), 'users_phone_unique')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This phone number is already registered.',
                    ], 422);
                }
                if (str_contains($e->getMessage(), 'users_email_unique')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This email address is already registered.',
                    ], 422);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
            ], 500);
        }
    }

    public function loginWithPassword(Request $request): JsonResponse
    {
        $authType = $this->determineAuthType($request);

        switch ($authType) {
            case 'email_password':
                return $this->handleEmailPasswordLogin($request);
            case 'email_only':
                return $this->handleEmailOnlyRegistration($request);
            case 'google':
                return $this->handleGoogleLogin($request);
            case 'apple':
                return $this->handleAppleLogin($request);
            case 'email':
                return $this->handleOtherEmailLogin($request);
            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid authentication method. Please provide email/password or email with auth_provider.',
                ], 422);
        }
    }

    private function determineAuthType(Request $request): string
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

    private function handleEmailPasswordLogin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],  // Password is now required for this login type
                'auth_provider' => ['required', 'string'],  // Auth provider is now required
                'device_token' => ['nullable', 'string'],
                'firebase_uid' => ['nullable', 'string'],
                'profile_image' => ['nullable'],  // Accept profile image as file or URL
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        // Normalize email: trim whitespace and remove quotes from both ends
        $email = trim($request->email);
        $email = trim($email, ' "\''); // Remove quotes and spaces
        $email = strtolower($email); // Normalize to lowercase for comparison

        // Also trim password and auth_provider
        $password = trim($request->password);
        $password = trim($password, ' "\'');
        $authProvider = trim($request->auth_provider);
        $authProvider = trim($authProvider, ' "\'');
        $deviceToken = $request->device_token ? trim($request->device_token, ' "\'') : null;

        // Use case-insensitive email lookup with TRIM to handle whitespace in database
        // Use withoutGlobalScopes and withTrashed to find user even if soft-deleted
        $user = User::withoutGlobalScopes()
            ->withTrashed()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where('role_id', 3)  // User role
            ->first();

        // Fallback: try exact match if first query didn't find user
        if (!$user) {
            $user = User::withoutGlobalScopes()
                ->withTrashed()
                ->where('email', $email)
                ->where('role_id', 3)
                ->first();
        }

        // Another fallback: try with original request email (in case normalization was too aggressive)
        if (!$user) {
            $originalEmail = trim($request->email);
            $user = User::withoutGlobalScopes()
                ->withTrashed()
                ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower($originalEmail)])
                ->where('role_id', 3)
                ->first();
        }

        // If user was soft-deleted, restore them
        if ($user && $user->trashed()) {
            $user->restore();
        }

        // Final fallback: check database directly using DB::table to bypass any model issues
        if (!$user) {
            $existingUser = DB::table('users')
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->where('role_id', 3)
                ->whereNull('deleted_at')
                ->first();

            if ($existingUser) {
                // User exists in database, load it using the model
                $user = User::withoutGlobalScopes()
                    ->withTrashed()
                    ->find($existingUser->id);
            }
        }

        $isNewUser = false;

        if (!$user) {
            // User doesn't exist, create new user
            try {
                $userData = [
                    'name' => '',  // Default name
                    'email' => $email,
                    'password' => Hash::make($password),
                    'role_id' => 3,  // User role
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'device_token' => $deviceToken ?? '',
                ];

                // Store firebase_uid if provided
                if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                    $userData['firebase_uid'] = $request->firebase_uid;
                }

                $profilePhotoPath = $this->handleProfileImage($request, null);
                if ($profilePhotoPath) {
                    $userData['profile_photo'] = $profilePhotoPath;
                }

                $user = User::create($userData);
                $isNewUser = true;
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle duplicate key error - user was created between our check and create
                if ($e->getCode() == 23000 && str_contains($e->getMessage(), 'users_email_role_unique')) {
                    // Retry lookup - user was just created by another request
                    $user = User::withoutGlobalScopes()
                        ->withTrashed()
                        ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                        ->where('role_id', 3)
                        ->first();

                    if (!$user) {
                        // Still not found, try DB::table
                        $existingUser = DB::table('users')
                            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                            ->where('role_id', 3)
                            ->whereNull('deleted_at')
                            ->first();

                        if ($existingUser) {
                            $user = User::withoutGlobalScopes()
                                ->withTrashed()
                                ->find($existingUser->id);
                        }
                    }

                    if (!$user) {
                        // If still not found, return error
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to create or find user account. Please try again.',
                        ], 500);
                    }

                    // User was found after duplicate error, verify password
                    if (!Hash::check($password, $user->password)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid email or password.',
                        ], 401);
                    }

                    // Update device token and firebase_uid if provided
                    $userData = [];
                    if ($deviceToken) {
                        $userData['device_token'] = $deviceToken;
                    }
                    if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                        $userData['firebase_uid'] = $request->firebase_uid;
                    }

                    $profilePhotoPath = $this->handleProfileImage($request, $user);
                    if ($profilePhotoPath) {
                        $userData['profile_photo'] = $profilePhotoPath;
                    }

                    if (!empty($userData)) {
                        $user->update($userData);
                    }
                } else {
                    // Re-throw if it's a different error
                    throw $e;
                }
            }
        } else {
            // User exists, verify password
            if (!Hash::check($password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid email or password.',
                ], 401);
            }

            // Update device token, firebase_uid, and profile photo if provided
            $userData = [];
            if ($deviceToken) {
                $userData['device_token'] = $deviceToken;
            }
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $userData['firebase_uid'] = $request->firebase_uid;
            }

            $profilePhotoPath = $this->handleProfileImage($request, $user);
            if ($profilePhotoPath) {
                $userData['profile_photo'] = $profilePhotoPath;
            }

            if (!empty($userData)) {
                $user->update($userData);
            }
        }

        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been blocked. Please contact support.',
            ], 422);
        }

        $authData = $this->createAuthToken($user, $deviceToken ?? '', 'email');

        $needsRegistration = $this->userNeedsRegistration($user);

        $userData = $authData['user'];
        $userData['is_email'] = 1;  // Email/password login
        $userData['new_register'] = $needsRegistration ? 1 : 0;  // 1 if needs registration, 0 if complete

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $authData['token'],
            'user' => $userData,
        ]);
    }

    private function handleEmailOnlyRegistration(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
                'device_token' => ['nullable', 'string'],
                'firebase_uid' => ['nullable', 'string'],
                'profile_image' => ['nullable'],  // Accept profile image as file or URL
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)
            ->where('role_id', 3)  // User role
            ->first();

        if (!$user) {
            $userData = [
                'name' => 'User',  // Default name
                'email' => $request->email,
                'password' => null,  // No password set
                'role_id' => 3,  // User role
                'status' => 'active',
                'email_verified_at' => now(),
                'device_token' => $request->device_token ?? '',
            ];

            // Store firebase_uid if provided
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $userData['firebase_uid'] = $request->firebase_uid;
            }

            $profilePhotoPath = $this->handleProfileImage($request, null);
            if ($profilePhotoPath) {
                $userData['profile_photo'] = $profilePhotoPath;
            }

            $user = User::create($userData);

            $authData = $this->createAuthToken($user, $request->device_token ?? '', 'email');

            $needsRegistration = $this->userNeedsRegistration($user);

            $userData = $authData['user'];
            $userData['is_email'] = 1;  // Email registration
            $userData['new_register'] = 1;  // New user created

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'token' => $authData['token'],
                'user' => $userData,
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'User already exists with this email address.',
            ], 422);
        }
    }

    private function handleGoogleLogin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
                'password' => ['nullable', 'string'],  // Password is optional for social login
                'name' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],
                'profile_photo' => ['nullable'],  // Accept profile photo as file or URL
                'profile_image' => ['nullable'],  // Accept profile image as file or URL
                'device_token' => ['nullable', 'string'],
                'firebase_uid' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)
            ->where('role_id', 3)
            ->first();

        $isNewUser = false;
        if (!$user) {
            $profilePhotoPath = $this->handleProfileImage($request, null);
            if (!$profilePhotoPath && $request->has('profile_photo') && !empty($request->profile_photo)) {
                if ($request->hasFile('profile_photo')) {
                    $profilePhotoPath = $request->file('profile_photo')->store('profile-photos', 'public');
                } else {
                    $profilePhotoPath = $request->profile_photo;
                }
            }

            $userData = [
                'name' => $request->name ?? '',  // Default name if not provided
                'email' => $request->email,
                'phone' => $request->phone,  // Optional phone for social login
                'role_id' => 3,
                'status' => 'active',
                'email_verified_at' => now(),  // Google accounts are pre-verified
                'profile_photo' => $profilePhotoPath,
                'device_token' => $request->device_token ?? '',
            ];

            // Store firebase_uid if provided
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $userData['firebase_uid'] = $request->firebase_uid;
            }

            $user = User::create($userData);
            $isNewUser = true;
        } else {
            // Update firebase_uid if provided
            $updateData = [];
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $updateData['firebase_uid'] = $request->firebase_uid;
            }

            $profilePhotoPath = $this->handleProfileImage($request, $user);
            if ($profilePhotoPath) {
                $updateData['profile_photo'] = $profilePhotoPath;
            } elseif ($request->has('profile_photo') && !empty($request->profile_photo)) {
                if ($request->hasFile('profile_photo')) {
                    if ($user->profile_photo) {
                        Storage::disk('public')->delete($user->profile_photo);
                    }
                    $profilePhotoPath = $request->file('profile_photo')->store('profile-photos', 'public');
                    $updateData['profile_photo'] = $profilePhotoPath;
                } else {
                    $updateData['profile_photo'] = $request->profile_photo;
                }
            }

            if (!empty($updateData)) {
                $user->update($updateData);
            }
        }

        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been blocked. Please contact support.',
            ], 422);
        }

        $authData = $this->createAuthToken($user, $request->device_token ?? '', 'google');

        $userData = $authData['user'];
        $userData['is_email'] = 0;  // Social login (Google)
        $userData['new_register'] = $isNewUser ? 1 : 0;  // 1 for new user, 0 for existing

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $authData['token'],
            'user' => $userData,
        ]);
    }

    private function handleAppleLogin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'id' => ['nullable', 'string'],
                'email' => ['required_without:id', 'email'],
                'password' => ['nullable', 'string'],  // Password is optional for social login
                'name' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],
                'profile_image' => ['nullable'],  // Accept profile image as file or URL
                'device_token' => ['nullable', 'string'],
                'firebase_uid' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        if ($request->has('id') && !empty($request->id)) {
            $user = User::where('id', $request->id)
                ->where('role_id', 3)  // User role
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

                $profilePhotoPath = $this->handleProfileImage($request, $user);
                if ($profilePhotoPath) {
                    $updateData['profile_photo'] = $profilePhotoPath;
                }

                if (!empty($updateData)) {
                    $user->update($updateData);
                }
            } else {
                if ($request->has('email')) {
                    $user = User::where('email', $request->email)
                        ->where('role_id', 3)  // User role
                        ->first();
                }
            }
        } else {
            $user = User::where('email', $request->email)
                ->where('role_id', 3)
                ->first();
        }

        $isNewUser = false;
        if (!$user) {
            $userData = [
                'name' => $request->name ?? null,  // Can be null if not provided
                'email' => $request->email ?? null,  // Can be null if not provided
                'phone' => $request->phone,  // Optional phone for social login
                'role_id' => 3,
                'status' => 'active',
                'email_verified_at' => now(),  // Apple accounts with email are pre-verified
                'device_token' => $request->device_token ?? '',
            ];

            // Store firebase_uid if provided
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $userData['firebase_uid'] = $request->firebase_uid;
            }

            $profilePhotoPath = $this->handleProfileImage($request, null);
            if ($profilePhotoPath) {
                $userData['profile_photo'] = $profilePhotoPath;
            }

            $user = User::create($userData);
            $isNewUser = true;
        } else {
            // Update firebase_uid if provided
            $updateData = [];
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $updateData['firebase_uid'] = $request->firebase_uid;
            }

            $profilePhotoPath = $this->handleProfileImage($request, $user);
            if ($profilePhotoPath) {
                $updateData['profile_photo'] = $profilePhotoPath;
            }

            if (!empty($updateData)) {
                $user->update($updateData);
            }
        }

        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been blocked. Please contact support.',
            ], 422);
        }

        $authData = $this->createAuthToken($user, $request->device_token ?? '', 'apple');

        $userData = $authData['user'];
        $userData['is_email'] = 0;  // Social login (Apple)
        $userData['new_register'] = $isNewUser ? 1 : 0;  // 1 for new user, 0 for existing

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $authData['token'],
            'user' => $userData,
        ]);
    }

    private function handleOtherEmailLogin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
                'password' => ['nullable', 'string'],  // Password is optional for other email login
                'name' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],
                'profile_image' => ['nullable'],  // Accept profile image as file or URL
                'device_token' => ['nullable', 'string'],
                'firebase_uid' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)
            ->where('role_id', 3)
            ->first();

        $isNewUser = false;
        if (!$user) {
            $userData = [
                'name' => $request->name ?? '',  // Use empty string when no name is provided
                'email' => $request->email,
                'phone' => $request->phone,  // Optional phone for other email login
                'role_id' => 3,
                'status' => 'active',
                'email_verified_at' => now(),  // Other email accounts are pre-verified
                'device_token' => $request->device_token ?? '',
            ];

            // Store firebase_uid if provided
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $userData['firebase_uid'] = $request->firebase_uid;
            }

            $profilePhotoPath = $this->handleProfileImage($request, null);
            if ($profilePhotoPath) {
                $userData['profile_photo'] = $profilePhotoPath;
            }

            $user = User::create($userData);
            $isNewUser = true;
        } else {
            // Update firebase_uid if provided
            $updateData = [];
            if ($request->has('firebase_uid') && !empty($request->firebase_uid)) {
                $updateData['firebase_uid'] = $request->firebase_uid;
            }

            $profilePhotoPath = $this->handleProfileImage($request, $user);
            if ($profilePhotoPath) {
                $updateData['profile_photo'] = $profilePhotoPath;
            }

            if (!empty($updateData)) {
                $user->update($updateData);
            }
        }

        if ($user->isBlocked()) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been blocked. Please contact support.',
            ], 422);
        }

        $authData = $this->createAuthToken($user, $request->device_token ?? '', 'email');

        $userData = $authData['user'];
        $userData['is_email'] = 0;  // Social login (Other Email)
        $userData['new_register'] = $isNewUser ? 1 : 0;  // 1 for new user, 0 for existing

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $authData['token'],
            'user' => $userData,
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)
            ->where('role_id', 3)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'If an account with this email exists, a password reset link has been sent.',
            ], 200);  // Don't reveal if email exists
        }

        $token = \Illuminate\Support\Str::random(64);
        $user->update([
            'password_reset_token' => $token,
            'password_reset_expires_at' => now()->addHours(1),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'If an account with this email exists, a password reset link has been sent.',
            'reset_token' => $token,  // Remove this in production
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'token' => ['required', 'string'],
                'password' => ['required', 'string', 'min:6'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::where('password_reset_token', $request->token)
            ->where('password_reset_expires_at', '>', now())
            ->where('role_id', 3)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'password_reset_token' => null,
            'password_reset_expires_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            // Store token before clearing for cache invalidation
            $oldToken = $user->bearer_token;

            // Expire token immediately by setting expiration to past time
            $user->update([
                'device_token' => '',
                'bearer_token' => null,
                'token_expires_at' => now()->subMinute(), // Set to 1 minute ago to ensure it's expired
                'is_online' => false,
                'last_seen_at' => now(),
            ]);

            // Clear cache for this token to ensure immediate invalidation
            if ($oldToken) {
                $token = str_replace('Bearer_', '', $oldToken);
                \Illuminate\Support\Facades\Cache::forget('auth:bearer_user:' . $token);
                \Illuminate\Support\Facades\Cache::forget('auth:bearer_user:Bearer_' . $token);
            }


            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully',
                'data' => [
                    'user_id' => (string) $user->id,
                    'logged_out_at' => now()->toISOString(),
                ]
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Logout failed. Please try again.',
            ], 500);
        }
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            // Trim email input for proper comparison
            $emailInput = $request->has('email') ? trim($request->input('email', '')) : null;
            $emailChanged = !empty($emailInput) && $emailInput !== $user->email;

            $emailRules = ['sometimes', 'nullable', 'email', 'max:255'];

            // Only add unique validation if email is being changed
            if ($emailChanged) {
                $emailRules[] = 'unique:users,email,' . $user->id . ',id,role_id,' . $user->role_id;
            }

            $data = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => $emailRules,
                'phone' => ['sometimes', 'required', 'string', 'max:20'],
                'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'profile_photo' => ['sometimes', 'required', 'image', 'max:2048'],  // 2MB max
                'country_code' => ['sometimes', 'required', 'string', 'max:5'],
            ]);

            // Trim string fields in validated data
            if (isset($data['email'])) {
                $data['email'] = trim($data['email']);
            }
            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
            }
            if (isset($data['address'])) {
                $data['address'] = trim($data['address']);
            }
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $errorMessages = [];

            foreach ($errors as $field => $messages) {
                $errorMessages[] = $field . ': ' . implode(', ', $messages);
            }

            return response()->json([
                'success' => false,
                'message' => implode('; ', $errorMessages),
            ], 422);
        }

        if ($request->hasFile('profile_photo')) {
            $path = $request->file('profile_photo')->store('profile-photos', 'public');
            $data['profile_photo'] = $path;

            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }
        }

        // Remove email from update data if it hasn't changed
        if (isset($data['email']) && $data['email'] === $user->email) {
            unset($data['email']);
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $this->getUserResponse($user),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'current_password' => ['required', 'string'],
                'new_password' => ['required', 'string', 'min:6', 'different:current_password'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The provided password is incorrect.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
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

    public function getTokenInfo(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'token_info' => [
                'token' => $user->bearer_token,
                'expires_at' => $user->token_expires_at,
                'is_expired' => $user->token_expires_at && $user->token_expires_at->isPast(),
            ],
        ]);
    }

    public function completeStep(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'step' => ['required', 'integer', 'min:0', 'max:3'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = $request->user();
        $step = $request->input('step');
        $stepColumn = 'step_' . $step;

        $user->update([$stepColumn => 1]);

        return response()->json([
            'success' => true,
            'message' => "Step {$step} completed successfully",
            'user' => $this->getUserResponse($user->fresh()),
        ]);
    }

    public function getStepStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'step_0' => $user->step_0 ?? 0,
                'step_1' => $user->step_1 ?? 0,
                'step_2' => $user->step_2 ?? 0,
                'step_3' => $user->step_3 ?? 0,
                'completed_steps' => [
                    'step_0' => (bool) ($user->step_0 ?? 0),
                    'step_1' => (bool) ($user->step_1 ?? 0),
                    'step_2' => (bool) ($user->step_2 ?? 0),
                    'step_3' => (bool) ($user->step_3 ?? 0),
                ],
                'total_completed' => ($user->step_0 ?? 0) + ($user->step_1 ?? 0) + ($user->step_2 ?? 0) + ($user->step_3 ?? 0),
                'all_steps_completed' => ($user->step_0 && $user->step_1 && $user->step_2 && $user->step_3),
            ],
        ]);
    }

    public function resetSteps(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'step_0' => 0,
            'step_1' => 0,
            'step_2' => 0,
            'step_3' => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'All steps reset successfully',
            'user' => $this->getUserResponse($user->fresh()),
        ]);
    }

    public function getUserProfile(Request $request): JsonResponse
    {
        $authUser = $request->user();

        if (!$authUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $user = User::with([
            'wallet',
            'wallet.transactions',
            'referrer',
            'bookingsAsUser.driver',
            'bookingsAsUser.driver.vehicles',
            'bookingsAsUser.driver.driverProfile',
            'bookingsAsUser.rideType',
            'bookingsAsUser.pickupZone.city',
            'bookingsAsUser.dropoffZone.city',
            'bookingsAsUser.user',
            'bookingsAsUser.transactions',
            'transactions',
            'promoUsages.promoCode',
            'supportTickets',
            'referrals'
        ])->findOrFail($authUser->id);

        $userData = [
            'id' => (string) $user->id,
            'name' => $user->name ?? '',
            'email' => $user->email ?? '',
            'phone' => $user->phone ?? '',
            'address' => $user->address ?? '',
            'country_code' => $user->country_code ?? '',
            'gender' => $user->gender ?? '',
            'date_of_birth' => $user->date_of_birth ? (is_string($user->date_of_birth) ? $user->date_of_birth : $user->date_of_birth->format('Y-m-d')) : '',
            'profile_photo' => $this->getProfilePhotoUrl($user->profile_photo),
            'role_id' => (string) $user->role_id,
            'role' => $this->getRoleName($user->role_id),
            'status' => $user->status ?? '',
            'is_online' => (string) ($user->is_online ? 1 : 0),
            'is_verified' => (string) ($user->is_verified ? 1 : 0),
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
            'refund_time' => (string) SystemConfiguration::getValue('refund_required_hours', 48),
            'available_payment' => $this->getAvailablePaymentMethods(),
        ];

        $wallet = $user->wallet;
        $userData['wallet'] = [
            'id' => (string) ($wallet?->id ?? ''),
            'balance' => (string) ($wallet?->balance ?? '0'),
            'currency' => $wallet?->currency ?? '',
            'created_at' => $wallet?->created_at?->format('Y-m-d H:i:s') ?? '',
            'updated_at' => $wallet?->updated_at?->format('Y-m-d H:i:s') ?? '',
        ];

        $referrer = $user->referrer;
        $userData['referrer'] = [
            'id' => (string) ($referrer?->id ?? ''),
            'name' => $referrer?->name ?? '',
            'phone' => $referrer?->phone ?? '',
            'referral_code' => $referrer?->referral_code ?? '',
        ];

        $userData['referrals_count'] = (string) $user->referrals()->count();

        $allBookings = $user->bookingsAsUser()->with([
            'driver',
            'driver.vehicles',
            'driver.driverProfile',
            'rideType',
            'pickupZone',
            'dropoffZone',
            'user'
        ])->get();

        $recentBookings = $allBookings->take(10);
        $userData['recent_bookings'] = $recentBookings->map(function ($booking) {
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
                'driver' => $booking->driver ? [
                    'id' => (string) $booking->driver->id,
                    'name' => $booking->driver->name ?? '',
                    'phone' => $booking->driver->phone ?? '',
                    'profile_photo' => $this->getProfilePhotoUrl($booking->driver->profile_photo),
                    'rating' => (string) ($booking->driver->driverProfile?->rating ?? ''),
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

        $userData['booking_statistics'] = [
            'total_bookings' => (string) $allBookings->count(),
            'completed_bookings' => (string) $allBookings->where('status', 'completed')->count(),
            'cancelled_bookings' => (string) $allBookings->where('status', 'cancelled')->count(),
            'pending_bookings' => (string) $allBookings->where('status', 'pending')->count(),
            'accepted_bookings' => (string) $allBookings->where('status', 'accepted')->count(),
            'started_bookings' => (string) $allBookings->where('status', 'started')->count(),
            'expired_bookings' => (string) $allBookings->where('status', 'expired')->count(),
            'total_spent' => (string) $allBookings->where('status', 'completed')->sum('total_amount'),
            'total_distance' => (string) $allBookings->where('status', 'completed')->sum('actual_distance'),
            'total_duration' => (string) $allBookings->where('status', 'completed')->sum('actual_duration'),
            'average_rating_given' => (string) $allBookings->where('status', 'completed')->whereNotNull('user_rating')->avg('user_rating'),
            'average_rating_received' => (string) $allBookings->where('status', 'completed')->whereNotNull('driver_rating')->avg('driver_rating'),
            'total_promo_savings' => (string) $allBookings->sum('discount_amount'),
            'wallet_usage' => (string) $allBookings->sum('wallet_amount'),
            'cash_payments' => (string) $allBookings->sum('cash_amount'),
            'online_payments' => (string) $allBookings->sum('online_paid_amount'),
        ];

        $userData['payment_methods'] = [
            'cash' => (string) $allBookings->where('payment_method', 'cash')->count(),
            'wallet' => (string) $allBookings->where('payment_method', 'wallet')->count(),
            'online' => (string) $allBookings->where('payment_method', 'online')->count(),
            'split' => (string) $allBookings->where('payment_method', 'split')->count(),
        ];

        $monthlyStats = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            $monthBookings = $allBookings->filter(function ($booking) use ($monthStart, $monthEnd) {
                return $booking->created_at >= $monthStart && $booking->created_at <= $monthEnd;
            });

            $monthlyStats[] = [
                'month' => $month->format('Y-m'),
                'month_name' => $month->format('F Y'),
                'total_bookings' => (string) $monthBookings->count(),
                'completed_bookings' => (string) $monthBookings->where('status', 'completed')->count(),
                'total_spent' => (string) $monthBookings->where('status', 'completed')->sum('total_amount'),
                'total_distance' => (string) $monthBookings->where('status', 'completed')->sum('actual_distance'),
            ];
        }
        $userData['monthly_statistics'] = $monthlyStats;

        $transactions = $user->transactions()->latest()->limit(20)->get();
        $userData['recent_transactions'] = $transactions->map(function ($transaction) {
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

        $promoUsages = $user->promoUsages()->with('promoCode')->latest()->limit(10)->get();
        $userData['promo_usage'] = $promoUsages->map(function ($usage) {
            return [
                'id' => (string) $usage->id,
                'promo_code' => $usage->promoCode?->code ?? '',
                'promo_description' => $usage->promoCode?->description ?? '',
                'original_amount' => (string) ($usage->original_amount ?? '0'),
                'discount_amount' => (string) ($usage->discount_amount ?? '0'),
                'final_amount' => (string) ($usage->final_amount ?? '0'),
                'booking_id' => (string) ($usage->booking_id ?? ''),
                'created_at' => $usage->created_at?->format('Y-m-d H:i:s') ?? '',
            ];
        })->toArray();

        $supportTickets = $user->supportTickets()->latest()->limit(10)->get();
        $userData['support_tickets'] = $supportTickets->map(function ($ticket) {
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
        $userData['wallet_transactions'] = $walletTransactions->map(function ($transaction) {
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

        $tripHistory = $user
            ->bookingsAsUser()
            ->with(['driver', 'driver.driverProfile', 'driver.vehicles', 'rideType', 'pickupZone', 'dropoffZone', 'transactions'])
            ->orderBy('created_at', 'desc')
            ->get();
        $userData['trip_history'] = $tripHistory->map(function ($booking) {
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
                'driver' => $booking->driver ? [
                    'id' => (string) $booking->driver->id,
                    'name' => $booking->driver->name ?? '',
                    'phone' => $booking->driver->phone ?? '',
                    'profile_photo' => $this->getProfilePhotoUrl($booking->driver->profile_photo),
                    'rating' => (string) ($booking->driver->driverProfile?->rating ?? ''),
                    'vehicle' => $booking->driver->vehicles()->first() ? [
                        'brand' => $booking->driver->vehicles()->first()->brand ?? '',
                        'model' => $booking->driver->vehicles()->first()->model ?? '',
                        'license_plate' => $booking->driver->vehicles()->first()->license_plate ?? '',
                        'color' => $booking->driver->vehicles()->first()->color ?? '',
                    ] : [
                        'brand' => '',
                        'model' => '',
                        'license_plate' => '',
                        'color' => '',
                    ],
                ] : [
                    'id' => '',
                    'name' => '',
                    'phone' => '',
                    'profile_photo' => '',
                    'rating' => '',
                    'vehicle' => [
                        'brand' => '',
                        'model' => '',
                        'license_plate' => '',
                        'color' => '',
                    ],
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

        $userData['trip_history_statistics'] = [
            'total_trips' => (string) $tripHistory->count(),
            'completed_trips' => (string) $tripHistory->where('status', 'completed')->count(),
            'cancelled_trips' => (string) $tripHistory->where('status', 'cancelled')->count(),
            'pending_trips' => (string) $tripHistory->where('status', 'pending')->count(),
            'accepted_trips' => (string) $tripHistory->where('status', 'accepted')->count(),
            'started_trips' => (string) $tripHistory->where('status', 'started')->count(),
            'expired_trips' => (string) $tripHistory->where('status', 'expired')->count(),
            'total_spent' => (string) $tripHistory->where('status', 'completed')->sum('total_amount'),
            'total_distance_traveled' => (string) $tripHistory->where('status', 'completed')->sum('actual_distance'),
            'total_time_spent' => (string) $tripHistory->where('status', 'completed')->sum('actual_duration'),
            'average_trip_cost' => (string) $tripHistory->where('status', 'completed')->avg('total_amount'),
            'average_trip_distance' => (string) $tripHistory->where('status', 'completed')->avg('actual_distance'),
            'average_trip_duration' => (string) $tripHistory->where('status', 'completed')->avg('actual_duration'),
            'average_rating_given' => (string) $tripHistory->where('status', 'completed')->whereNotNull('user_rating')->avg('user_rating'),
            'average_rating_received' => (string) $tripHistory->where('status', 'completed')->whereNotNull('driver_rating')->avg('driver_rating'),
            'total_promo_savings' => (string) $tripHistory->sum('discount_amount'),
            'total_wallet_used' => (string) $tripHistory->sum('wallet_amount'),
            'total_cash_paid' => (string) $tripHistory->sum('cash_amount'),
            'total_online_paid' => (string) $tripHistory->sum('online_paid_amount'),
            'completion_rate' => $tripHistory->count() > 0 ? (string) (($tripHistory->where('status', 'completed')->count() / $tripHistory->count()) * 100) : '0',
            'cancellation_rate' => $tripHistory->count() > 0 ? (string) (($tripHistory->where('status', 'cancelled')->count() / $tripHistory->count()) * 100) : '0',
        ];

        $userData['trip_history_by_status'] = [
            'completed' => $tripHistory->where('status', 'completed')->map(function ($booking) {
                return [
                    'id' => (string) $booking->id,
                    'booking_code' => $booking->booking_code ?? '',
                    'total_amount' => (string) ($booking->total_amount ?? '0'),
                    'actual_distance' => (string) ($booking->actual_distance ?? '0'),
                    'actual_duration' => (string) ($booking->actual_duration ?? '0'),
                    'driver_rating' => (string) ($booking->driver_rating ?? '0'),
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

        $userData['comprehensive_statistics'] = [
            'total_transactions' => (string) $user->transactions()->count(),
            'total_promo_usages' => (string) $user->promoUsages()->count(),
            'total_support_tickets' => (string) $user->supportTickets()->count(),
            'open_support_tickets' => (string) $user->supportTickets()->where('status', 'open')->count(),
            'resolved_support_tickets' => (string) $user->supportTickets()->where('status', 'resolved')->count(),
            'total_wallet_transactions' => (string) ($user->wallet?->transactions()->count() ?? 0),
            'total_promo_savings' => (string) $user->promoUsages()->sum('discount_amount'),
            'total_transaction_amount' => (string) $user->transactions()->where('wallet_transactions.status', 'completed')->sum('amount'),
        ];

        $currentBooking = Booking::where('user_id', $user->id)
            ->whereIn('status', ['searching', 'accepted', 'arrived', 'started', 'completed'])
            ->where('payment_status', 'pending')
            ->with(['driver', 'driver.vehicles', 'driver.driverProfile', 'rideType', 'user'])
            ->latest()
            ->first();

        $currentBookingData = null;
        if ($currentBooking) {
            $currentBookingData = $this->formatBookingResponse($currentBooking);
        }

        $allBookingsFormatted = $allBookings->map(function ($booking) {
            return $this->formatBookingResponse($booking);
        })->toArray();

        $responseData = [
            'success' => true,
            'message' => 'User profile retrieved successfully',
            'data' => $userData,
        ];

        if ($currentBookingData && (($currentBookingData['booking']['payment_status'] ?? '') === 'pending')) {
            $responseData['current_booking'] = $currentBookingData;
        }

        // Set is_cash to 1 only if payment method from init-transaction is cash
        // We check the latest transaction's payment_method as it's set when init-transaction is called
        if ($currentBooking) {
            // Check the latest transaction's payment_method (authoritative source from init-transaction)
            $latestTransaction = \App\Models\Transaction::where('booking_id', $currentBooking->id)
                ->where('type', 'payment')
                ->latest()
                ->first();

            if ($latestTransaction) {
                $paymentMethod = strtolower(trim($latestTransaction->payment_method ?? ''));
                // Transaction stores razorpay as 'card', stripe as 'stripe', etc.
                // Set is_cash to 1 only if payment_method is 'cash'
                $responseData['is_cash'] = ($paymentMethod === 'cash') ? 1 : 0;
            } else {
                // No transaction exists yet (init-transaction not called), so is_cash should be 0
                $responseData['is_cash'] = 0;
            }
        } else {
            $responseData['is_cash'] = 0;
        }

        $responseData['all_bookings'] = null;

        return response()->json($responseData);
    }

    private function formatBookingResponse($booking): array
    {
        $driver = $booking->driver;
        $vehicle = $driver && $driver->vehicles ? $driver->vehicles->first() : null;
        $driverProfile = $driver ? $driver->driverProfile : null;

        $passenger = [
            'name' => $booking->user->name ?? '',
            'phone' => $booking->user->phone ?? '',
            'photo' => $this->getProfilePhotoUrl($booking->user->profile_photo ?? ''),
            'rating' => (string) ($booking->user_rating ?? '0'),
        ];

        $tripDetails = [
            'distance' => (string) ($booking->actual_distance ?? $booking->estimated_distance ?? '0'),
            'fare' => (string) ($booking->total_amount ?? $booking->estimated_fare ?? '0'),
            'duration' => (string) ($booking->actual_duration ?? $booking->estimated_duration ?? '0'),
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
            'estimated_fare' => (string) ($booking->estimated_fare ?? $booking->total_amount ?? '0'),
            'actual_fare' => (string) ($booking->total_amount ?? '0'),
            'distance' => (string) ($booking->distance ?? '0'),
            'final_fare' => (string) ($booking->total_amount ?? $booking->estimated_fare ?? '0'),
            'otp' => $booking->otp ?? '',
            'trip_code' => $booking->trip_code ?? '',
            'discount_amount' => (string) ($booking->discount_amount ?? '0'),
            'promo_code' => (string) ($booking->promo_code ?? ''),
            'created_at' => $booking->created_at?->toISOString() ?? '',
            'updated_at' => $booking->updated_at?->toISOString() ?? '',
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
                'base_price' => (string) ($booking->rideType->base_fare ?? '0'),
                'price_per_km' => (string) ($booking->rideType->per_km_rate ?? '0'),
                'price_per_minute' => (string) ($booking->rideType->per_minute_rate ?? '0'),
                'waiting_time_limit' => (string) ($booking->rideType->waiting_time_limit ?? '0'),
                'waiting_charge_per_minute' => (string) ($booking->rideType->waiting_charge_per_minute ?? '0'),
            ] : [
                'id' => '',
                'name' => '',
                'base_price' => '0',
                'price_per_km' => '0',
                'price_per_minute' => '0',
                'waiting_time_limit' => (string) ($booking->rideType->waiting_time_limit ?? '0'),
                'waiting_charge_per_minute' => (string) ($booking->rideType->waiting_charge_per_minute ?? '0'),
            ],
        ];

        $invoice = [
            'invoice_number' => 'INV-' . date('Y') . '-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT),
            'booking_code' => $booking->booking_code ?? '',
            'invoice_date' => $booking->completed_at?->format('Y-m-d H:i:s') ?? ($booking->created_at?->format('Y-m-d H:i:s') ?? ''),
            'customer' => [
                'name' => $booking->user->name ?? '',
                'phone' => $booking->user->phone ?? '',
                'email' => $booking->user->email ?? '',
            ],
            'driver' => $driver ? [
                'name' => $driver->name ?? '',
                'phone' => $driver->phone ?? '',
                'vehicle' => $vehicle ? ($vehicle->model ?? '') : '',
                'license_plate' => $vehicle ? ($vehicle->license_plate ?? '') : '',
            ] : [
                'name' => '',
                'phone' => '',
                'vehicle' => '',
                'license_plate' => '',
            ],
            'payment_details' => [
                'payment_method' => $booking->payment_method ?? '',
                'payment_status' => $booking->payment_status ?? '',
            ],
        ];

        $driverData = $driver ? [
            'id' => (string) $driver->id,
            'name' => $driver->name ?? '',
            'phone' => $driver->phone ?? '',
            'rating' => (string) ($driverProfile ? ($driverProfile->rating ?? '') : ''),
            'vehicle' => [
                'model' => $vehicle ? ($vehicle->model ?? '') : '',
                'number_plate' => $vehicle ? ($vehicle->license_plate ?? '') : '',
            ],
            'is_online' => (string) ($driver->is_online ?? '0'),
        ] : [
            'id' => '',
            'name' => '',
            'phone' => '',
            'rating' => '0',
            'vehicle' => [
                'model' => '',
                'number_plate' => '',
            ],
            'is_online' => '0',
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

        return [
            'booking_id' => (string) $booking->id,
            'passenger' => $passenger,
            'trip_details' => $tripDetails,
            'acceptance_timer' => $booking->status === 'accepted' ? 30 : 0,
            'booking' => $bookingData,
            'invoice' => $invoice,
            'driver' => $driverData,
            'pickup' => $pickup,
            'dropoff' => $dropoff,
            'event_type' => 'booking_status_changed',
            'status' => $booking->status ?? '',
            'timestamp' => $booking->updated_at?->toISOString() ?? ($booking->created_at?->toISOString() ?? ''),
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

    protected function getRoleName(int $roleId): string
    {
        $roles = [
            1 => 'admin',
            2 => 'driver',
            3 => 'user',
        ];

        return $roles[$roleId] ?? '';
    }

    private function userNeedsRegistration(User $user): bool
    {
        $requiredFields = ['name', 'phone', 'gender'];

        foreach ($requiredFields as $field) {
            if (empty($user->$field)) {
                return true;
            }
        }

        return false;
    }

    private function handleProfileImage(Request $request, ?User $user = null): ?string
    {
        if ($request->hasFile('profile_image')) {
            if ($user && $user->profile_photo) {
                if (!filter_var($user->profile_photo, FILTER_VALIDATE_URL)) {
                    Storage::disk('public')->delete($user->profile_photo);
                }
            }

            return $request->file('profile_image')->store('profile-photos', 'public');
        }

        if ($request->has('profile_image') && !empty($request->profile_image)) {
            $profileImage = $request->profile_image;

            if (filter_var($profileImage, FILTER_VALIDATE_URL)) {
                return $profileImage;
            }

            return $profileImage;
        }

        return null;
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            // Block deletion for user ID 2 (demo mode)
            if ($user->id === 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'In demo mode you are not deleting data...',
                ], 403);
            }

            $activeBookings = $user
                ->bookingsAsUser()
                ->whereIn('status', ['accepted', 'started'])
                ->count();
            if ($activeBookings > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete account with active bookings. Please complete or cancel your active rides first.',
                    'data' => [
                        'active_bookings_count' => (string) $activeBookings,
                    ]
                ], 422);
            }

            $walletBalance = $user->wallet?->balance ?? 0;

            // Refresh user from database to ensure we have latest data including firebase_uid
            $user->refresh();

            $userId = $user->id;
            $userEmail = $user->email;
            $userPhone = $user->phone;
            $userName = $user->name;
            $userFirebaseUid = $user->firebase_uid;

            DB::beginTransaction();

            try {

                if ($user->wallet) {
                    $user->wallet->transactions()->delete();
                    $user->wallet->delete();
                }

                $user->promoUsages()->delete();

                $user->supportTickets()->delete();

                $user->emergencyContacts()->delete();

                $user->documents()->delete();

                $user->notifications()->delete();

                // Get bookings where user is the customer
                $userBookings = Booking::where('user_id', $userId)->get();

                // Delete only related records that belong to this user before deleting bookings
                // This prevents cascade deletes from affecting other users' data
                foreach ($userBookings as $booking) {
                    // Delete all chats for this booking (they are between customer and driver)
                    // We delete all because the booking belongs to this user
                    Chat::where('booking_id', $booking->id)->delete();

                    // Delete issue reports where user is the user (not driver)
                    IssueReport::where('booking_id', $booking->id)
                        ->where('user_id', $userId)->delete();

                    // Delete driver ratings where user is the rider
                    DriverRating::where('booking_id', $booking->id)
                        ->where('rider_id', $userId)->delete();

                    // Delete refund requests where user is the requester
                    RefundRequest::where('booking_id', $booking->id)
                        ->where('user_id', $userId)->delete();

                    // Delete support chats where user is involved
                    SupportChat::where('booking_id', $booking->id)
                        ->where('user_id', $userId)->delete();

                    // Delete cancellation fee disputes where user is involved
                    CancellationFeeDispute::where('booking_id', $booking->id)
                        ->where('user_id', $userId)->delete();
                }

                // Now safely delete bookings where user is the customer
                // All related records that could affect other users have been manually deleted
                Booking::where('user_id', $userId)->forceDelete();

                $user->referrals()->update(['referred_by' => null]);

                if ($user->isDriver()) {
                    if ($user->driverProfile) {
                        $user->driverProfile->delete();
                    }

                    $user->vehicles()->delete();

                    $user->driverAttendance()->delete();

                    $user->driverLocations()->delete();

                    // Get bookings where user is the driver
                    $driverBookings = Booking::where('driver_id', $userId)->get();

                    // Delete only related records that belong to this driver before deleting bookings
                    // This prevents cascade deletes from affecting other users' data
                    foreach ($driverBookings as $booking) {
                        // Delete all chats for this booking (they are between customer and driver)
                        // We delete all because the booking belongs to this driver
                        Chat::where('booking_id', $booking->id)->delete();

                        // Delete issue reports where driver is the driver (not user)
                        IssueReport::where('booking_id', $booking->id)
                            ->where('driver_id', $userId)->delete();

                        // Delete driver ratings where driver is the driver
                        DriverRating::where('booking_id', $booking->id)
                            ->where('driver_id', $userId)->delete();

                        // Delete cancellation fee disputes where driver is involved
                        CancellationFeeDispute::where('booking_id', $booking->id)
                            ->where('driver_id', $userId)->delete();
                    }

                    // Now safely delete bookings where user is the driver
                    // All related records that could affect other users have been manually deleted
                    Booking::where('driver_id', $userId)->forceDelete();
                }

                // Delete Firebase user before deleting from database
                try {
                    $firebaseService = app(FirebaseService::class);
                    // Accept firebase_uid from request if provided, otherwise use stored value or email
                    $firebaseUid = $request->input('firebase_uid') ?? $userFirebaseUid ?? $user->firebase_uid;
                    $firebaseService->deleteUser($firebaseUid, $userEmail ?? $user->email, $userPhone ?? $user->phone);
                } catch (\Exception $e) {
                    // Continue with database deletion even if Firebase deletion fails
                }

                $user->forceDelete();

                DB::commit();


                return response()->json([
                    'success' => true,
                    'message' => 'Account deleted successfully. All your data has been permanently removed.',
                    'data' => [
                        'deleted_at' => now()->toISOString(),
                        'user_id' => (string) $userId,
                    ]
                ]);
            } catch (\Exception $e) {
                DB::rollback();


                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Account deletion failed: ' . $e->getMessage(),
                'error_details' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            ], 500);
        }
    }

    public function deactivateAccount(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $request->validate([
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            $activeBookings = $user
                ->bookingsAsUser()
                ->whereIn('status', ['pending', 'accepted', 'started'])
                ->count();

            if ($activeBookings > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot deactivate account with active bookings. Please complete or cancel your active rides first.',
                    'data' => [
                        'active_bookings_count' => (string) $activeBookings,
                    ]
                ], 422);
            }

            $user->update([
                'status' => 'inactive',
                'is_online' => false,
                'bearer_token' => null,
                'token_expires_at' => null,
                'device_token' => '',
                'deactivated_at' => now(),
                'deactivation_reason' => $request->input('reason', 'User requested account deactivation'),
                'last_seen_at' => now(),
            ]);


            return response()->json([
                'success' => true,
                'message' => 'Account deactivated successfully. You can reactivate it anytime by logging in.',
                'data' => [
                    'user_id' => (string) $user->id,
                    'deactivated_at' => now()->toISOString(),
                    'status' => 'inactive',
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Account deactivation failed. Please try again.',
            ], 500);
        }
    }

    protected function processReferralRewards(User $referredUser, User $referrer): void
    {
        try {
            // Ensure both users have the same role_id (role_id 3 for users)
            if ($referredUser->role_id !== $referrer->role_id || $referredUser->role_id !== 3) {
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
            $availablePayments[] = 'cash';
        }

        return $availablePayments;
    }
}
