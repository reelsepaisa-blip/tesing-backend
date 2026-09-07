<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }


    public function addMoney(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', 'in:cash,card,bank_transfer'],
            'payment_details' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $transaction = $this->walletService->topUp(
                $request->user(),
                $request->amount,
                $validator->validated()['description'] ?? 'Wallet top-up',
                $validator->validated()
            );

            return response()->json([
                'message' => 'Money added successfully',
                'transaction' => $transaction->load('wallet'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to add money',
                'error' => $e->getMessage(),
            ], 422);
        }
    }


    public function transfer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $recipient = User::findOrFail($request->recipient_id);
            $transactions = $this->walletService->transfer(
                $request->user(),
                $recipient,
                $request->amount,
                $validator->validated()
            );

            return response()->json([
                'message' => 'Money transferred successfully',
                'transactions' => [
                    'debit' => $transactions['debit']->load('wallet'),
                    'credit' => $transactions['credit']->load('wallet'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to transfer money',
                'error' => $e->getMessage(),
            ], 422);
        }
    }


    public function transactions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => ['nullable', 'string', 'in:credit,debit,hold,release,refund'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = $request->user()
            ->transactions()
            ->with('wallet')
            ->latest();

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $transactions = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'transactions' => $transactions,
        ]);
    }


    public function balance(Request $request): JsonResponse
    {
        // Return demo data if demo mode is enabled
        if (\App\Services\DemoModeService::isEnabled()) {
            $demoBalance = \App\Services\DemoModeService::getDemoWalletBalance();
            return response()->json([
                'balance' => $demoBalance,
                'hold_amount' => 0,
                'available_balance' => $demoBalance,
                'is_active' => true,
                'last_transaction_at' => now()->toISOString(),
            ]);
        }

        $wallet = $request->user()->wallet;

        if (!$wallet) {
            return response()->json([
                'balance' => 0,
                'hold_amount' => 0,
                'available_balance' => 0,
                'is_active' => false,
            ]);
        }

        return response()->json([
            'balance' => $wallet->balance,
            'hold_amount' => $wallet->hold_amount,
            'available_balance' => $wallet->balance - $wallet->hold_amount,
            'is_active' => $wallet->is_active,
            'last_transaction_at' => $wallet->last_transaction_at,
        ]);
    }


    public function getWalletInfoTransactions(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet;

        $balanceInfo = [
            'month' => now()->format('M Y'),
            'balance' => 0,
            'hold_amount' => 0,
            'available_balance' => 0,
            'is_active' => false,
            'last_transaction_at' => '',
            'total_credit' => 0,
            'total_debit' => 0,
        ];

        if ($wallet) {
            $balanceInfo = [
                'month' => now()->format('M Y'),
                'balance' => $wallet->balance,
                'hold_amount' => $wallet->hold_amount ?? 0,
                'available_balance' => $wallet->balance - ($wallet->hold_amount ?? 0),
                'is_active' => $wallet->is_active ?? true,
                'last_transaction_at' => $wallet->last_transaction_at ?? '',
                'total_credit' => $wallet->total_credit ?? 0,
                'total_debit' => $wallet->total_debit ?? 0,
            ];
        }

        $query = $user->transactions()->with('wallet')->latest();

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $perPage = $request->per_page ?? 15;
        $transactions = $query->paginate($perPage);

        $formattedTransactions = $transactions->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'balance' => $transaction->balance,
                'description' => $transaction->description,
                'status' => $transaction->status,
                'reference_type' => $transaction->reference_type,
                'reference_id' => (string) $transaction->reference_id,
                'created_at' => $transaction->created_at->toISOString(),
                'updated_at' => $transaction->updated_at->toISOString(),
                'meta_data' => $transaction->meta_data,
                'month' => $transaction->created_at->format('M Y'),
                'date' => $transaction->created_at->format('M d, Y'),
                'time' => $transaction->created_at->format('g:i A'),
                'is_refunded' => str_contains($transaction->type, 'refund') ? 1 : 0,
                'referral_bonus' => $transaction->type === 'referral_bonus' ? 1 : 0,
            ];
        });

        $groupedTransactions = $formattedTransactions->groupBy('month')->map(function ($monthTransactions, $month) {
            $monthlyCredits = $monthTransactions->where('amount', '>', 0)->sum('amount');
            $monthlyDebits = abs($monthTransactions->where('amount', '<', 0)->sum('amount'));
            $monthlyBalance = $monthlyCredits - $monthlyDebits;

            return [
                'month' => $month,
                'total_amount' => number_format($monthlyBalance, 2),
                'transactions' => $monthTransactions->values()->all()
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'wallet_info' => $balanceInfo,
                'transactions' => [
                    'data' => $groupedTransactions,
                    'current_page' => $transactions->currentPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                    'last_page' => $transactions->lastPage(),
                    'from' => $transactions->firstItem() ?? 0,
                    'to' => $transactions->lastItem() ?? 0,
                ],
                'stats' => [
                    'total_transactions' => $transactions->total(),
                    'credit_count' => $formattedTransactions->where('amount', '>', 0)->count(),
                    'debit_count' => $formattedTransactions->where('amount', '<', 0)->count(),
                    'total_credit_amount' => $formattedTransactions->where('amount', '>', 0)->sum('amount') ?: 0,
                    'total_debit_amount' => abs($formattedTransactions->where('amount', '<', 0)->sum('amount')) ?: 0,
                ]
            ]
        ]);
    }
}
