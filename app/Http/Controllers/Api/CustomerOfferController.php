<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\City;
use App\Models\PromoCode;
use App\Models\PromoUsage;
use App\Models\User;
use App\Services\FareService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerOfferController extends Controller
{
    protected FareService $fareService;

    public function __construct(FareService $fareService)
    {
        $this->fareService = $fareService;
    }

    public function getAvailableOffers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:0',
            'ride_type_id' => 'nullable|integer|exists:ride_types,id',
            'city_id' => 'nullable|integer|exists:cities,id',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = auth()->user();
            $amount = $request->input('amount', 0);
            $rideTypeId = $request->input('ride_type_id');
            $cityId = $request->input('city_id');

            $query = PromoCode::active()
                ->where('is_referral_code', false)  // Only regular promo codes, not referral codes
                ->where(function ($q) {
                    $q
                        ->whereNull('starts_at')
                        ->orWhere('starts_at', '<=', now());
                });

            if ($amount > 0) {
                $query->where(function ($q) use ($amount) {
                    $q
                        ->whereNull('min_order_amount')
                        ->orWhere('min_order_amount', '<=', $amount);
                });
            }

            if ($rideTypeId) {
                $query->where(function ($q) use ($rideTypeId) {
                    $q
                        ->whereNull('ride_type_ids')
                        ->orWhereJsonContains('ride_type_ids', $rideTypeId);
                });
            }

            if ($cityId) {
                $query->where(function ($q) use ($cityId) {
                    $q
                        ->whereNull('city_ids')
                        ->orWhereJsonContains('city_ids', $cityId);
                });
            }

            $promoCodes = $query->get();

            $availableOffers = $promoCodes->filter(function ($promo) use ($user, $amount) {
                if ($promo->hasUserReachedMaxUses($user)) {
                    return false;
                }

                if ($promo->is_first_ride_only && $user->bookings()->where('status', 'completed')->count() > 0) {
                    return false;
                }

                if ($promo->hasReachedMaxUses()) {
                    return false;
                }

                return true;
            })->map(function ($promo) use ($amount) {
                $discount = $promo->calculateDiscount($amount);
                $finalAmount = max(0, $amount - $discount);

                return [
                    'id' => $promo->id,
                    'code' => $promo->code,
                    'description' => $promo->description,
                    'type' => $promo->type,
                    'value' => $promo->value,
                    'discount_amount' => $discount,
                    'final_amount' => $finalAmount,
                    'min_order_amount' => $promo->min_order_amount,
                    'max_discount_amount' => $promo->max_discount_amount,
                    'expires_at' => $promo->expires_at,
                    'is_first_ride_only' => $promo->is_first_ride_only,
                    'usage_count' => $promo->usages()->count(),
                    'max_uses_total' => $promo->max_uses_total,
                    'max_uses_per_user' => $promo->max_uses_per_user,
                    'user_usage_count' => $promo->usages()->where('user_id', auth()->id())->count(),
                ];
            })->values();

            $personalizedOffers = $this->getPersonalizedOffers($user, $amount, $rideTypeId, $cityId);

            return response()->json([
                'success' => true,
                'data' => [
                    'available_offers' => $availableOffers,
                    'personalized_offers' => $personalizedOffers,
                    'total_offers' => $availableOffers->count() + count($personalizedOffers),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch offers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function applyPromoCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'promo_code' => 'required|string',
            'booking_id' => 'required|integer|exists:bookings,id',
            'ride_type_id' => 'required|integer|exists:ride_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = auth()->user();
            $promoCode = $request->input('promo_code');
            $bookingId = $request->input('booking_id');

            $booking = Booking::where('id', $bookingId)
                ->where('user_id', $user->id)
                ->first();

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found or does not belong to you'
                ], 404);
            }
            $rideTypeId = $request->input('ride_type_id');
            $promo = PromoCode::where('code', $promoCode)->first();
            $applicableRideTypes = db::table('promo_code_ride_types')->where('promo_code_id', $promo->id)->get();
            $applicableRideTypesIds = $applicableRideTypes->pluck('ride_type_id');
            if (!in_array($rideTypeId, $applicableRideTypesIds->toArray())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promo code is not applicable for this ride type'
                ], 400);
            }
            if (in_array($booking->status, ['cancelled', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot apply promo code to cancelled or completed bookings'
                ], 400);
            }

            $promo = PromoCode::where('code', $promoCode)->first();

            if (!$promo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid promo code'
                ], 404);
            }

            if (!$promo->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This promo code is not active or has expired'
                ], 400);
            }

            if ($promo->hasReachedMaxUses()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This promo code has reached its usage limit'
                ], 400);
            }

            if ($promo->max_uses_per_user) {
                $usageCount = PromoUsage::where('user_id', $user->id)
                    ->where('promo_code_id', $promo->id)
                    ->where('booking_id', '!=', $booking->id)  // Exclude current booking
                    ->count();

                if ($usageCount >= $promo->max_uses_per_user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You have already used this promo code maximum times'
                    ], 400);
                }
            }

            if ($promo->is_first_ride_only && $user->bookings()->where('status', 'completed')->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'This promo code is only for first-time users'
                ], 400);
            }

            if ($promo->min_order_amount && $booking->estimated_fare < $promo->min_order_amount) {
                return response()->json([
                    'success' => false,
                    'message' => "Minimum order amount of ₹{$promo->min_order_amount} required. Your booking amount is ₹{$booking->estimated_fare}"
                ], 400);
            }

            if ($promo->type === 'fixed' && $promo->value > $booking->estimated_fare) {
                return response()->json([
                    'success' => false,
                    'message' => "This promo code discount (₹{$promo->value}) is greater than your booking amount (₹{$booking->estimated_fare})"
                ], 400);
            }

            $applicableCities = $promo->cities()->pluck('cities.id')->toArray();
            if (!empty($applicableCities) && !in_array($booking->city_id, $applicableCities)) {
                $city = City::find($booking->city_id);
                $cityName = $city ? $city->name : 'your city';

                return response()->json([
                    'success' => false,
                    'message' => "This promo code is not applicable in {$cityName}"
                ], 400);
            }

            if (!empty($promo->ride_type_ids)) {
                $allowedRideTypes = $promo->ride_type_ids;
                if (!empty($allowedRideTypes) && !in_array($request->ride_type_id, $allowedRideTypes)) {
                    $rideType = \App\Models\RideType::find($request->ride_type_id);
                    $rideTypeName = $rideType ? $rideType->name : 'selected ride type';

                    return response()->json([
                        'success' => false,
                        'message' => "This promo code is not valid for {$rideTypeName}."
                    ], 400);
                }
            }

            if (!empty($promo->user_types)) {
                $userTypes = $promo->user_types;
                if (!empty($userTypes) && !in_array('all', $userTypes)) {
                }
            }

            DB::beginTransaction();

            try {
                $isChanging = !empty($booking->promo_code);

                // Recalculate fare for the requested ride type
                $fareParams = [
                    'city_id' => $booking->city_id,
                    'ride_type_id' => $rideTypeId,
                    'pickup_latitude' => $booking->pickup_latitude,
                    'pickup_longitude' => $booking->pickup_longitude,
                    'distance' => $booking->distance,
                    'duration' => $booking->duration,
                ];
                $fareBreakdown = $this->fareService->calculateEstimatedFare($fareParams);
                $originalAmount = (float) $fareBreakdown['total'];

                if ($originalAmount <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid booking amount for promo code application'
                    ], 400);
                }

                $discountAmount = $promo->calculateDiscount($originalAmount);
                $finalAmount = max(0, $originalAmount - $discountAmount);

                if ($finalAmount == 0 && $discountAmount < $originalAmount) {
                    $finalAmount = max(0.01, $originalAmount - $discountAmount);
                }

                $booking->update([
                    'ride_type_id' => $rideTypeId,
                    'estimated_fare' => $finalAmount,
                    'final_fare' => $finalAmount,
                    'discount_amount' => $discountAmount,
                    'promo_code' => $promo->code,
                    'surge_multiplier' => $fareBreakdown['surge_multiplier'],
                    'surge_amount' => $fareBreakdown['surge_amount'],
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => $isChanging ? 'Promo code changed successfully' : 'Promo code applied successfully',
                    'data' => [
                        'promo_code' => $promo->code,
                        'description' => $promo->description,
                        'original_amount' => $originalAmount,
                        'discount_amount' => $discountAmount,
                        'final_amount' => $finalAmount,
                        'promo_type' => $promo->type,
                        'promo_value' => $promo->value,
                    ]
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply promo code',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function removePromoCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|integer|exists:bookings,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = auth()->user();
            $bookingId = $request->input('booking_id');

            $booking = Booking::where('id', $bookingId)
                ->where('user_id', $user->id)
                ->whereIn('status', ['pending', 'accepted'])
                ->with('promoUsage')
                ->first();

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found'
                ], 404);
            }

            if (!$booking->promo_code) {
                return response()->json([
                    'success' => false,
                    'message' => 'No promo code applied to this booking'
                ], 400);
            }

            DB::beginTransaction();

            try {
                $originalAmount = $booking->estimated_fare + ($booking->discount_amount ?? 0);

                $booking->update([
                    'promo_code' => null,
                    'estimated_fare' => $originalAmount,
                    'discount_amount' => 0,
                ]);

                \App\Models\PromoUsage::where('booking_id', $booking->id)->delete();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Promo code removed successfully',
                    'data' => [
                        'original_amount' => $booking->estimated_fare,
                        'discount_amount' => 0,
                        'final_amount' => $booking->estimated_fare,
                    ]
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove promo code',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUsageHistory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'nullable|integer|min:1|max:50',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = auth()->user();
            $perPage = $request->input('per_page', 15);

            $usageHistory = PromoUsage::where('user_id', $user->id)
                ->with(['promoCode', 'booking'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            $formattedHistory = $usageHistory->map(function ($usage) {
                return [
                    'id' => $usage->id,
                    'promo_code' => $usage->promoCode->code,
                    'description' => $usage->promoCode->description,
                    'original_amount' => $usage->original_amount,
                    'discount_amount' => $usage->discount_amount,
                    'final_amount' => $usage->final_amount,
                    'booking_id' => $usage->booking_id,
                    'booking_status' => $usage->booking->status,
                    'applied_at' => $usage->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'usage_history' => $formattedHistory,
                    'pagination' => [
                        'current_page' => $usageHistory->currentPage(),
                        'last_page' => $usageHistory->lastPage(),
                        'per_page' => $usageHistory->perPage(),
                        'total' => $usageHistory->total(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch usage history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getPersonalizedOffers(User $user, float $amount, ?int $rideTypeId, ?int $cityId): array
    {
        $offers = [];

        if ($user->bookings()->where('status', 'completed')->count() === 0) {
            $offers[] = [
                'id' => 'first_ride',
                'code' => 'WELCOME20',
                'description' => 'Welcome! Get 20% off your first ride',
                'type' => 'percentage',
                'value' => 20,
                'discount_amount' => $amount * 0.2,
                'final_amount' => $amount * 0.8,
                'is_personalized' => true,
                'expires_at' => now()->addDays(7),
            ];
        }

        $completedRides = $user->bookings()->where('status', 'completed')->count();
        if ($completedRides >= 10) {
            $offers[] = [
                'id' => 'loyalty',
                'code' => 'LOYALTY50',
                'description' => 'Thank you for being a loyal customer! ₹50 off',
                'type' => 'fixed',
                'value' => 50,
                'discount_amount' => min(50, $amount),
                'final_amount' => max(0, $amount - 50),
                'is_personalized' => true,
                'expires_at' => now()->addDays(30),
            ];
        }

        if (now()->isWeekend()) {
            $offers[] = [
                'id' => 'weekend',
                'code' => 'WEEKEND15',
                'description' => 'Weekend special! 15% off your ride',
                'type' => 'percentage',
                'value' => 15,
                'discount_amount' => $amount * 0.15,
                'final_amount' => $amount * 0.85,
                'is_personalized' => true,
                'expires_at' => now()->endOfWeek(),
            ];
        }

        $hour = now()->hour;
        if (($hour >= 7 && $hour <= 9) || ($hour >= 17 && $hour <= 19)) {
            $offers[] = [
                'id' => 'rush_hour',
                'code' => 'RUSH10',
                'description' => 'Rush hour special! ₹10 off your ride',
                'type' => 'fixed',
                'value' => 10,
                'discount_amount' => min(10, $amount),
                'final_amount' => max(0, $amount - 10),
                'is_personalized' => true,
                'expires_at' => now()->addHours(2),
            ];
        }

        return $offers;
    }

    public function validatePromoCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'promo_code' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'ride_type_id' => 'nullable|integer|exists:ride_types,id',
            'city_id' => 'nullable|integer|exists:cities,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = auth()->user();
            $promoCode = $request->input('promo_code');
            $amount = $request->input('amount');

            $promo = PromoCode::where('code', $promoCode)->first();

            if (!$promo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid promo code'
                ], 404);
            }

            if (!$promo->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This promo code is not active'
                ], 400);
            }

            if ($promo->hasReachedMaxUses()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This promo code has reached its usage limit'
                ], 400);
            }

            if ($promo->hasUserReachedMaxUses($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already used this promo code maximum times'
                ], 400);
            }

            if ($promo->min_order_amount && $amount < $promo->min_order_amount) {
                return response()->json([
                    'success' => false,
                    'message' => "Minimum order amount of ₹{$promo->min_order_amount} required"
                ], 400);
            }

            if ($request->has('city_id') && $request->city_id) {
                $applicableCities = $promo->cities()->pluck('cities.id')->toArray();
                if (!empty($applicableCities) && !in_array($request->city_id, $applicableCities)) {
                    $city = City::find($request->city_id);
                    $cityName = $city ? $city->name : 'this city';

                    return response()->json([
                        'success' => false,
                        'message' => "This promo code is not applicable in {$cityName}"
                    ], 400);
                }
            }

            $discountAmount = $promo->calculateDiscount($amount);
            $finalAmount = max(0, $amount - $discountAmount);

            return response()->json([
                'success' => true,
                'message' => 'Promo code is valid',
                'data' => [
                    'promo_code' => $promo->code,
                    'description' => $promo->description,
                    'type' => $promo->type,
                    'value' => $promo->value,
                    'original_amount' => $amount,
                    'discount_amount' => $discountAmount,
                    'final_amount' => $finalAmount,
                    'min_order_amount' => $promo->min_order_amount,
                    'max_discount_amount' => $promo->max_discount_amount,
                    'expires_at' => $promo->expires_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to validate promo code',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getOfferList(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'nullable|string|exists:bookings,id',
            'search' => 'nullable|string|max:255',
            'type' => 'nullable|in:fixed,percentage,cashback',
            'min_value' => 'nullable|numeric|min:0',
            'max_value' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'city_id' => 'nullable|integer|exists:cities,id',
            'ride_type_id' => 'nullable|integer|exists:ride_types,id',
            'is_first_ride_only' => 'nullable|boolean',
            'is_referral_code' => 'nullable|boolean',
            'expires_before' => 'nullable|date',
            'expires_after' => 'nullable|date',
            'sort_by' => 'nullable|in:created_at,expires_at,value,min_order_amount,usage_count',
            'sort_order' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cityId = null;
            $rideTypeId = null;

            // Get city_id and ride_type_id from booking if booking_id is provided
            if ($request->filled('booking_id')) {
                $bookingId = $request->input('booking_id');
                $booking = Booking::find($bookingId);
                if ($booking) {
                    if ($booking->city_id) {
                        $cityId = $booking->city_id;
                    }
                    if ($booking->ride_type_id) {
                        $rideTypeId = $booking->ride_type_id;
                    }
                }
            }

            // Allow request parameters to override or set values
            if ($request->filled('city_id')) {
                $cityId = $request->integer('city_id');
            }
            if ($request->filled('ride_type_id')) {
                $rideTypeId = $request->integer('ride_type_id');
            }

            $query = PromoCode::with('rideTypes');

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q
                        ->where('code', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%");
                });
            }

            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }

            if ($request->filled('status')) {
                $status = $request->input('status');
                if ($status === 'expired') {
                    $query->where('expires_at', '<', now());
                } elseif ($status === 'active') {
                    $query
                        ->where('status', 'active')
                        ->where(function ($q) {
                            $q
                                ->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now());
                        });
                } elseif ($status === 'inactive') {
                    $query->where('status', 'inactive');
                } elseif (is_numeric($status)) {
                    $query->where('status', $status);
                }
            }

            if ($request->filled('min_value')) {
                $query->where('value', '>=', $request->input('min_value'));
            }
            if ($request->filled('max_value')) {
                $query->where('value', '<=', $request->input('max_value'));
            }

            if ($request->filled('min_order_amount')) {
                $query->where(function ($q) {
                    $q
                        ->whereNull('min_order_amount')
                        ->orWhere('min_order_amount', '<=', request('min_order_amount'));
                });
            }

            // Filter by city_id - check both JSON column and pivot table
            if ($cityId) {
                $query->where(function ($q) use ($cityId) {
                    // Show promo codes that either:
                    // 1. Have no city restrictions (null/empty city_ids AND no pivot entries) - available for all cities
                    // 2. Have city restrictions that include this city_id (in JSON column OR pivot table)
                    $q
                        ->where(function ($noRestrictionQ) {
                            // No restrictions: null/empty city_ids and no pivot entries
                            $noRestrictionQ
                                ->where(function ($emptyCheck) {
                                    $emptyCheck
                                        ->whereNull('city_ids')
                                        ->orWhere('city_ids', '[]')
                                        ->orWhere('city_ids', '')
                                        ->orWhereRaw('JSON_LENGTH(COALESCE(city_ids, "[]")) = 0');
                                })
                                ->whereDoesntHave('cities');
                        })
                        ->orWhere(function ($hasRestrictionQ) use ($cityId) {
                            // Has restrictions: must match city_id
                            $hasRestrictionQ->where(function ($matchQ) use ($cityId) {
                                // Check JSON column
                                $matchQ
                                    ->where(function ($jsonQ) use ($cityId) {
                                        $jsonQ
                                            ->whereNotNull('city_ids')
                                            ->where('city_ids', '!=', '[]')
                                            ->where('city_ids', '!=', '')
                                            ->whereRaw('JSON_LENGTH(city_ids) > 0')
                                            ->whereJsonContains('city_ids', $cityId);
                                    })
                                    // OR check pivot table
                                    ->orWhereHas('cities', function ($cityQuery) use ($cityId) {
                                        $cityQuery->where('cities.id', $cityId);
                                    });
                            });
                        });
                });
            }

            // Filter by ride_type_id - check both JSON column and pivot table
            if ($rideTypeId) {
                $query->where(function ($q) use ($rideTypeId) {
                    // Show promo codes that either:
                    // 1. Have no ride_type restrictions (null/empty ride_type_ids AND no pivot entries) - available for all ride types
                    // 2. Have ride_type restrictions that include this ride_type_id (in JSON column OR pivot table)
                    $q
                        ->where(function ($noRestrictionQ) {
                            // No restrictions: null/empty ride_type_ids and no pivot entries
                            $noRestrictionQ
                                ->where(function ($emptyCheck) {
                                    $emptyCheck
                                        ->whereNull('ride_type_ids')
                                        ->orWhere('ride_type_ids', '[]')
                                        ->orWhere('ride_type_ids', '')
                                        ->orWhereRaw('JSON_LENGTH(COALESCE(ride_type_ids, "[]")) = 0');
                                })
                                ->whereDoesntHave('rideTypes');
                        })
                        ->orWhere(function ($hasRestrictionQ) use ($rideTypeId) {
                            // Has restrictions: must match ride_type_id
                            $hasRestrictionQ->where(function ($matchQ) use ($rideTypeId) {
                                // Check JSON column
                                $matchQ
                                    ->where(function ($jsonQ) use ($rideTypeId) {
                                        $jsonQ
                                            ->whereNotNull('ride_type_ids')
                                            ->where('ride_type_ids', '!=', '[]')
                                            ->where('ride_type_ids', '!=', '')
                                            ->whereRaw('JSON_LENGTH(ride_type_ids) > 0')
                                            ->whereJsonContains('ride_type_ids', $rideTypeId);
                                    })
                                    // OR check pivot table
                                    ->orWhereHas('rideTypes', function ($rideTypeQuery) use ($rideTypeId) {
                                        $rideTypeQuery->where('ride_types.id', $rideTypeId);
                                    });
                            });
                        });
                });
            }

            if ($request->filled('is_first_ride_only')) {
                $query->where('is_first_ride_only', $request->boolean('is_first_ride_only'));
            }

            if ($request->filled('is_referral_code')) {
                $query->where('is_referral_code', $request->boolean('is_referral_code'));
            }

            if ($request->filled('expires_before')) {
                $query->where('expires_at', '<=', $request->input('expires_before'));
            }
            if ($request->filled('expires_after')) {
                $query->where('expires_at', '>=', $request->input('expires_after'));
            }

            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');

            if ($sortBy === 'usage_count') {
                $query->withCount('usages')->orderBy('usages_count', $sortOrder);
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }

            $perPage = $request->input('per_page', 10);
            $offers = $query->paginate($perPage);

            // Additional filtering: Ensure promo codes match city_id and ride_type_id restrictions
            // This ensures that promo codes with restrictions only show if they match
            $filteredCollection = $offers->getCollection()->filter(function ($promo) use ($cityId, $rideTypeId) {
                // Check city restrictions
                if ($cityId) {
                    $hasCityRestrictions = false;
                    $cityMatches = false;

                    // Check JSON column
                    $cityIds = $promo->city_ids;
                    if (!empty($cityIds) && is_array($cityIds) && count($cityIds) > 0) {
                        $hasCityRestrictions = true;
                        $cityMatches = in_array($cityId, $cityIds);
                    }

                    // Check pivot table
                    $pivotCities = $promo->cities()->pluck('cities.id')->toArray();
                    if (!empty($pivotCities)) {
                        $hasCityRestrictions = true;
                        if (!$cityMatches) {
                            $cityMatches = in_array($cityId, $pivotCities);
                        }
                    }

                    // If has restrictions but doesn't match, exclude this promo code
                    if ($hasCityRestrictions && !$cityMatches) {
                        return false;
                    }
                }

                // Check ride_type restrictions
                if ($rideTypeId) {
                    $hasRideTypeRestrictions = false;
                    $rideTypeMatches = false;

                    // Check JSON column
                    $rideTypeIds = $promo->ride_type_ids;
                    if (!empty($rideTypeIds) && is_array($rideTypeIds) && count($rideTypeIds) > 0) {
                        $hasRideTypeRestrictions = true;
                        $rideTypeMatches = in_array($rideTypeId, $rideTypeIds);
                    }

                    // Check pivot table
                    $pivotRideTypes = $promo->rideTypes()->pluck('ride_types.id')->toArray();
                    if (!empty($pivotRideTypes)) {
                        $hasRideTypeRestrictions = true;
                        if (!$rideTypeMatches) {
                            $rideTypeMatches = in_array($rideTypeId, $pivotRideTypes);
                        }
                    }

                    // If has restrictions but doesn't match, exclude this promo code
                    if ($hasRideTypeRestrictions && !$rideTypeMatches) {
                        return false;
                    }
                }

                return true;  // Include this promo code
            });

            // Update the collection with filtered results
            $offers->setCollection($filteredCollection);

            $formattedOffers = $offers->map(function ($promo) use ($rideTypeId) {
                $usageCount = $promo->usages()->count();
                $isExpired = $promo->expires_at && $promo->expires_at < now();
                $isActive = $promo->status === 'active' && !$isExpired;
                $hasReachedMaxUses = $promo->hasReachedMaxUses();

                $applicableRideTypes = [];
                $rideTypes = $promo->rideTypes;
                if ($rideTypes->isNotEmpty()) {
                    // Filter ride types by ride_type_id if provided
                    if ($rideTypeId) {
                        $rideTypes = $rideTypes->filter(function ($rideType) use ($rideTypeId) {
                            return $rideType->id == $rideTypeId;
                        });
                    }

                    $applicableRideTypes = $rideTypes->map(function ($rideType) {
                        return [
                            'name' => strtolower($rideType->name),
                            'icon' => $this->getRideTypeIconUrl($rideType->icon)
                        ];
                    })->values()->toArray();
                }

                $row1Text = $this->processDynamicText('Cannot be combined with other offers', $promo);
                $row2Text = $this->processDynamicText('Get {{₹ value}} off your next ride.', $promo);
                $row3Text = $this->processDynamicText('Minimum fare {{₹ min_order_amount}}', $promo);

                return [
                    'id' => $promo->id,
                    'code' => $promo->code ?? '',
                    'description' => $promo->description ?? '',
                    'type' => $promo->type ?? '',
                    'value' => $promo->value ?? '',
                    'min_order_amount' => $promo->min_order_amount ?? '',
                    'max_discount_amount' => $promo->max_discount_amount ?? '',
                    'max_uses_per_user' => $promo->max_uses_per_user ?? null,
                    'max_uses_total' => $promo->max_uses_total ?? '',
                    'is_first_ride_only' => $promo->is_first_ride_only ?? false,
                    'is_referral_code' => $promo->is_referral_code ?? false,
                    'status' => $promo->status ?? '',
                    'starts_at' => $promo->starts_at ?? '',
                    'expires_at' => $promo->expires_at ?? '',
                    'is_expired' => $isExpired,
                    'is_active' => $isActive,
                    'is_available' => $isActive && !$hasReachedMaxUses,
                    'usage_count' => $usageCount,
                    'remaining_uses' => $promo->max_uses_total ? max(0, $promo->max_uses_total - $usageCount) : '',
                    'usage_percentage' => $promo->max_uses_total ? round(($usageCount / $promo->max_uses_total) * 100, 2) : '',
                    'city_ids' => $promo->city_ids ?? [],
                    'applicable_ride_types' => $applicableRideTypes,
                    'first_row_text' => $row1Text,
                    'second_row_text' => $row2Text,
                    'third_row_text' => $row3Text,
                    'created_at' => $promo->created_at ?? '',
                    'updated_at' => $promo->updated_at ?? '',
                ];
            })->filter(function ($offer) use ($rideTypeId) {
                // If ride_type_id is provided, only include offers with non-empty applicable_ride_types
                if ($rideTypeId && empty($offer['applicable_ride_types'])) {
                    return false;
                }

                // Exclude expired offers
                if ($offer['is_expired']) {
                    return false;
                }

                return true;
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Offer list retrieved successfully',
                'data' => [
                    'offers' => $formattedOffers,
                    'pagination' => [
                        'current_page' => $offers->currentPage(),
                        'last_page' => $offers->lastPage(),
                        'per_page' => $offers->perPage(),
                        'total' => $offers->total(),
                        'from' => $offers->firstItem(),
                        'to' => $offers->lastItem(),
                        'has_more_pages' => $offers->hasMorePages(),
                    ],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch offer list',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getOffersByRideType(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ride_type_id' => 'required|integer|exists:ride_types,id',
            'city_id' => 'nullable|integer|exists:cities,id',
            'amount' => 'nullable|numeric|min:0',
            'per_page' => 'nullable|integer|min:1|max:50',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $rideTypeId = $request->input('ride_type_id');
            $cityId = $request->input('city_id');
            $amount = $request->input('amount', 0);

            $query = PromoCode::query()
                ->where('status', '1')  // Active status
                ->where('is_referral_code', false)  // Only regular promo codes
                ->where(function ($q) {
                    $q
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                });

            $query->where(function ($q) use ($rideTypeId) {
                $q
                    ->whereNull('ride_type_ids')
                    ->orWhere('ride_type_ids', '[]')
                    ->orWhereJsonContains('ride_type_ids', $rideTypeId);
            });

            if ($cityId) {
                $query->where(function ($q) use ($cityId) {
                    $q
                        ->whereNull('city_ids')
                        ->orWhere('city_ids', '[]')
                        ->orWhereJsonContains('city_ids', $cityId);
                });
            }

            if ($amount > 0) {
                $query->where(function ($q) use ($amount) {
                    $q
                        ->whereNull('min_order_amount')
                        ->orWhere('min_order_amount', '<=', $amount);
                });
            }

            $query->orderBy('created_at', 'desc');

            $perPage = $request->input('per_page', 10);
            $offers = $query->paginate($perPage);

            $rideTypes = \App\Models\RideType::pluck('name', 'id')->toArray();

            $formattedOffers = $offers->map(function ($promo) use ($rideTypeId, $amount, $rideTypes) {
                $usageCount = $promo->usages()->count();
                $isExpired = $promo->expires_at && $promo->expires_at < now();
                $isActive = $promo->status === '1' && !$isExpired;
                $hasReachedMaxUses = $promo->hasReachedMaxUses();

                $discountAmount = $amount > 0 ? $promo->calculateDiscount($amount) : $promo->value;
                $finalAmount = $amount > 0 ? max(0, $amount - $discountAmount) : 0;

                $rideTypeNames = '';
                if (!empty($promo->ride_type_ids)) {
                    $rideTypeArray = is_array($promo->ride_type_ids) ? $promo->ride_type_ids : json_decode($promo->ride_type_ids, true);
                    if (!empty($rideTypeArray)) {
                        $names = [];
                        foreach ($rideTypeArray as $id) {
                            if (isset($rideTypes[$id])) {
                                $names[] = strtolower($rideTypes[$id]);
                            }
                        }
                        $rideTypeNames = implode(',', $names);
                    }
                }

                $row1Text = $this->processDynamicText('Cannot be combined with other offers', $promo);
                $row2Text = $this->processDynamicText('Get {{₹ value}} off your next ride.', $promo);
                $row3Text = $this->processDynamicText('Minimum fare {{₹ min_order_amount}}', $promo);

                return [
                    'id' => $promo->id,
                    'code' => $promo->code ?? '',
                    'description' => $promo->description ?? '',
                    'type' => $promo->type ?? '',
                    'value' => $promo->value ?? '',
                    'min_order_amount' => $promo->min_order_amount ?? '',
                    'max_discount_amount' => $promo->max_discount_amount ?? '',
                    'is_first_ride_only' => $promo->is_first_ride_only ?? false,
                    'status' => $promo->status ?? '',
                    'expires_at' => $promo->expires_at ?? '',
                    'is_expired' => $isExpired,
                    'is_active' => $isActive,
                    'is_available' => $isActive && !$hasReachedMaxUses,
                    'usage_count' => $usageCount,
                    'remaining_uses' => $promo->max_uses_total ? max(0, $promo->max_uses_total - $usageCount) : '',
                    'city_ids' => $promo->city_ids ?? [],
                    'applicable_ride_types' => $rideTypeNames,
                    'first_row_text' => $row1Text,
                    'second_row_text' => $row2Text,
                    'third_row_text' => $row3Text,
                    'discount_preview' => [
                        'original_amount' => $amount,
                        'discount_amount' => $discountAmount,
                        'final_amount' => $finalAmount,
                    ],
                    'created_at' => $promo->created_at ?? '',
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Offers for ride type retrieved successfully',
                'data' => [
                    'ride_type_id' => $rideTypeId,
                    'city_id' => $cityId,
                    'amount' => $amount,
                    'offers' => $formattedOffers,
                    'pagination' => [
                        'current_page' => $offers->currentPage(),
                        'last_page' => $offers->lastPage(),
                        'per_page' => $offers->perPage(),
                        'total' => $offers->total(),
                        'from' => $offers->firstItem(),
                        'to' => $offers->lastItem(),
                        'has_more_pages' => $offers->hasMorePages(),
                        'first_row_text' => 'Cannot be combined with other offers',
                        'second_row_text' => 'Get {{₹ value}} off your next ride.',
                        'third_row_text' => 'Minimum fare ₹{{min_order_amount}}'
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch offers for ride type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function processDynamicText(string $text, $promo): string
    {
        if (empty($text)) {
            return '';
        }

        $replacements = [
            '{{value}}' => $promo->value ?? '',
            '{{₹ value}}' => '₹' . ($promo->value ?? ''),
            '{{min_order_amount}}' => $promo->min_order_amount ?? '',
            '{{₹ min_order_amount}}' => '₹' . ($promo->min_order_amount ?? ''),
            '{{max_discount_amount}}' => $promo->max_discount_amount ?? '',
            '{{₹ max_discount_amount}}' => '₹' . ($promo->max_discount_amount ?? ''),
            '{{type}}' => $promo->type ?? '',
            '{{code}}' => $promo->code ?? '',
            '{{description}}' => $promo->description ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    protected function getRideTypeIconUrl(?string $icon): string
    {
        if (empty($icon)) {
            return '';
        }

        if (filter_var($icon, FILTER_VALIDATE_URL)) {
            return $icon;
        }

        return url('storage/' . $icon);
    }
}
