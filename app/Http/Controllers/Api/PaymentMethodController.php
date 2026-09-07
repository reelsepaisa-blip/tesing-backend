<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PaymentMethodController extends Controller
{
    
    public function index(Request $request): JsonResponse
    {
        try {
            $query = PaymentMethod::active()->ordered();

            if ($request->has('type')) {
                $query->where('type', $request->input('type'));
            }

            if ($request->has('is_online')) {
                $query->where('is_online', $request->boolean('is_online'));
            }

            $paymentMethods = $query->get()->map(function ($method) {
                return [
                    'id' => $method->id,
                    'name' => $method->name ?? '',
                    'code' => $method->code ?? '',
                    'type' => $method->type ?? '',
                    'description' => $method->description ?? '',
                    'icon' => $method->icon ?? '',
                    'color' => $method->color ?? '',
                    'is_online' => $method->is_online,
                    'requires_verification' => $method->requires_verification,
                    'min_amount' => $method->min_amount ?? '',
                    'max_amount' => $method->max_amount ?? '',
                    'processing_fee_percentage' => $method->processing_fee_percentage ?? '',
                    'processing_fee_fixed' => $method->processing_fee_fixed ?? '',
                    'status' => $method->status ?? '',
                    'status_message' => $method->status_message ?? '',
                    'is_available' => $method->isAvailable(),
                    'is_maintenance' => $method->isMaintenance(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $paymentMethods,
                'total' => $paymentMethods->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment methods',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $paymentMethod = PaymentMethod::findOrFail($id);

            if (!$paymentMethod->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method is not available'
                ], 404);
            }

            $data = [
                'id' => $paymentMethod->id,
                'name' => $paymentMethod->name ?? '',
                'code' => $paymentMethod->code ?? '',
                'type' => $paymentMethod->type ?? '',
                'description' => $paymentMethod->description ?? '',
                'icon' => $paymentMethod->icon ?? '',
                'color' => $paymentMethod->color ?? '',
                'is_online' => $paymentMethod->is_online,
                'requires_verification' => $paymentMethod->requires_verification,
                'min_amount' => $paymentMethod->min_amount ?? '',
                'max_amount' => $paymentMethod->max_amount ?? '',
                'processing_fee_percentage' => $paymentMethod->processing_fee_percentage ?? '',
                'processing_fee_fixed' => $paymentMethod->processing_fee_fixed ?? '',
                'status' => $paymentMethod->status ?? '',
                'status_message' => $paymentMethod->status_message ?? '',
                'is_available' => $paymentMethod->isAvailable(),
                'is_maintenance' => $paymentMethod->isMaintenance(),
            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment method not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    
    public function calculateFee(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $paymentMethod = PaymentMethod::findOrFail($request->input('payment_method_id'));

            if (!$paymentMethod->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method is not available'
                ], 400);
            }

            $amount = $request->input('amount');

            if (!$paymentMethod->isAmountValid($amount)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Amount is not within the valid range for this payment method'
                ], 400);
            }

            $processingFee = $paymentMethod->calculateProcessingFee($amount);
            $totalAmount = $amount + $processingFee;

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_method' => [
                        'id' => $paymentMethod->id,
                        'name' => $paymentMethod->name ?? '',
                        'code' => $paymentMethod->code ?? '',
                    ],
                    'original_amount' => $amount,
                    'processing_fee' => $processingFee,
                    'total_amount' => $totalAmount,
                    'fee_breakdown' => [
                        'percentage_fee' => ($amount * $paymentMethod->processing_fee_percentage) / 100,
                        'fixed_fee' => $paymentMethod->processing_fee_fixed,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate processing fee',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function adminIndex(Request $request): JsonResponse
    {
        try {
            $query = PaymentMethod::query();

            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->has('type')) {
                $query->where('type', $request->input('type'));
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            }

            $paymentMethods = $query->ordered()->paginate($request->input('per_page', 15));

            $formattedData = $paymentMethods->map(function ($method) {
                return [
                    'id' => $method->id,
                    'name' => $method->name ?? '',
                    'code' => $method->code ?? '',
                    'type' => $method->type ?? '',
                    'description' => $method->description ?? '',
                    'icon' => $method->icon ?? '',
                    'color' => $method->color ?? '',
                    'is_active' => $method->is_active,
                    'is_online' => $method->is_online,
                    'requires_verification' => $method->requires_verification,
                    'min_amount' => $method->min_amount ?? '',
                    'max_amount' => $method->max_amount ?? '',
                    'processing_fee_percentage' => $method->processing_fee_percentage ?? '',
                    'processing_fee_fixed' => $method->processing_fee_fixed ?? '',
                    'sort_order' => $method->sort_order ?? '',
                    'status' => $method->status ?? '',
                    'status_message' => $method->status_message ?? '',
                    'status_badge_color' => $method->getStatusBadgeColor(),
                    'type_badge_color' => $method->getTypeBadgeColor(),
                    'is_available' => $method->isAvailable(),
                    'is_maintenance' => $method->isMaintenance(),
                    'created_at' => $method->created_at,
                    'updated_at' => $method->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
                'pagination' => [
                    'current_page' => $paymentMethods->currentPage(),
                    'last_page' => $paymentMethods->lastPage(),
                    'per_page' => $paymentMethods->perPage(),
                    'total' => $paymentMethods->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment methods',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:payment_methods,code',
            'type' => 'required|string|in:cash,card,wallet,online',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:7',
            'is_active' => 'boolean',
            'is_online' => 'boolean',
            'requires_verification' => 'boolean',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'processing_fee_percentage' => 'nullable|numeric|min:0|max:100',
            'processing_fee_fixed' => 'nullable|numeric|min:0',
            'sort_order' => 'nullable|integer|min:0',
            'configuration' => 'nullable|array',
            'supported_currencies' => 'nullable|array',
            'supported_countries' => 'nullable|array',
            'status' => 'required|string|in:active,inactive,maintenance',
            'status_message' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $paymentMethod = PaymentMethod::create($validator->validated());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment method created successfully',
                'data' => $paymentMethod
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment method',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function update(Request $request, $id): JsonResponse
    {
        $paymentMethod = PaymentMethod::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:payment_methods,code,' . $id,
            'type' => 'sometimes|required|string|in:cash,card,wallet,online',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:7',
            'is_active' => 'boolean',
            'is_online' => 'boolean',
            'requires_verification' => 'boolean',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'processing_fee_percentage' => 'nullable|numeric|min:0|max:100',
            'processing_fee_fixed' => 'nullable|numeric|min:0',
            'sort_order' => 'nullable|integer|min:0',
            'configuration' => 'nullable|array',
            'supported_currencies' => 'nullable|array',
            'supported_countries' => 'nullable|array',
            'status' => 'sometimes|required|string|in:active,inactive,maintenance',
            'status_message' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $paymentMethod->update($validator->validated());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment method updated successfully',
                'data' => $paymentMethod
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment method',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function destroy($id): JsonResponse
    {
        try {
            $paymentMethod = PaymentMethod::findOrFail($id);

            $usageCount = $paymentMethod->bookings()->count();
            if ($usageCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete payment method. It is being used in {$usageCount} bookings."
                ], 400);
            }

            DB::beginTransaction();

            $paymentMethod->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment method deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete payment method',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function updateStatus(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:active,inactive,maintenance',
            'status_message' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $paymentMethod = PaymentMethod::findOrFail($id);

            DB::beginTransaction();

            $paymentMethod->update($validator->validated());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment method status updated successfully',
                'data' => [
                    'id' => $paymentMethod->id,
                    'name' => $paymentMethod->name ?? '',
                    'status' => $paymentMethod->status ?? '',
                    'status_message' => $paymentMethod->status_message ?? '',
                    'is_active' => $paymentMethod->is_active,
                    'is_available' => $paymentMethod->isAvailable(),
                    'is_maintenance' => $paymentMethod->isMaintenance(),
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment method status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
