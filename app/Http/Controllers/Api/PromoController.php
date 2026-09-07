<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Services\PromoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class PromoController extends Controller
{
    protected PromoService $promoService;

    public function __construct(PromoService $promoService)
    {
        $this->promoService = $promoService;
    }

    
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if ($value && PromoCode::where('code', $value)->whereNull('deleted_at')->exists()) {
                        $fail('The promo code has already been taken.');
                    }
                }
            ],
            'type' => ['required', 'string', 'in:fixed,percentage,cashback,referral'],
            'value' => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) use ($request) {
                    $type = $request->input('type');
                    if ($type === 'percentage') {
                        if ($value > 100) {
                            $fail('The percentage value cannot be greater than 100.');
                        }
                        if ($value <= 0) {
                            $fail('The percentage value must be greater than 0.');
                        }
                    } else {
                        if ($value <= 0) {
                            $fail('The discount value must be greater than 0.');
                        }
                    }
                }
            ],
            'description' => ['required', 'string'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'max_uses_total' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_user' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:active,inactive,expired'],
            'ride_types' => ['nullable', 'array'],
            'ride_types.*' => ['integer', 'exists:ride_types,id'],
            'cities' => ['nullable', 'array'],
            'cities.*' => ['integer', 'exists:cities,id'],
            'user_types' => ['nullable', 'array'],
            'user_types.*' => ['string', 'in:new,existing,all'],
            'meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $promo = $this->promoService->createPromo($validator->validated());

            return response()->json([
                'message' => 'Promo code created successfully',
                'promo' => $promo,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create promo code',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function validate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
            'ride_type_id' => ['required', 'integer', 'exists:ride_types,id'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'user_type' => ['required', 'string', 'in:new,existing'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $result = $this->promoService->applyPromo(
                $request->code,
                array_merge($validator->validated(), [
                    'user_id' => $request->user()->id,
                ])
            );

            return response()->json([
                'message' => 'Promo code is valid',
                'promo' => $result['promo'],
                'discount' => $result['discount'],
                'fare_discount' => $result['fare_discount'],
                'final_amount' => $result['final_amount'],
                'cashback_amount' => $result['cashback_amount'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Invalid promo code',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    
    public function available(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['nullable', 'numeric', 'min:0'],
            'ride_type_id' => ['nullable', 'integer', 'exists:ride_types,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = PromoCode::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            });

        if ($request->amount) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('min_order_amount')
                    ->orWhere('min_order_amount', '<=', $request->amount);
            });
        }

        if ($request->ride_type_id) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('ride_types')
                    ->orWhereJsonContains('ride_types', $request->ride_type_id);
            });
        }

        if ($request->city_id) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('cities')
                    ->orWhereJsonContains('cities', $request->city_id);
            });
        }

        $userType = $request->user()->bookings()->exists() ? 'existing' : 'new';
        $query->where(function ($q) use ($userType) {
            $q->whereNull('user_types')
                ->orWhereJsonContains('user_types', $userType)
                ->orWhereJsonContains('user_types', 'all');
        });

        $query->where(function ($q) {
            $q->whereNull('max_uses_total')
                ->orWhereRaw('(select count(*) from promo_usages where promo_code_id = promo_codes.id) < max_uses_total');
        });

        $promos = $query->get();

        $promos = $promos->filter(function ($promo) use ($request) {
            if (!$promo->max_uses_per_user) {
                return true;
            }

            $userUsages = $promo->usages()
                ->where('user_id', $request->user()->id)
                ->count();

            return $userUsages < $promo->max_uses_per_user;
        });

        return response()->json([
            'promos' => $promos->values(),
        ]);
    }

    
    public function history(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $usages = $request->user()
            ->promoUsages()
            ->with('promo')
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'usages' => $usages,
        ]);
    }
}
