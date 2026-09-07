<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RefundRequest extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_PARTIALLY_APPROVED = 'partially_approved';
    const STATUS_REJECTED = 'rejected';

    const SOURCE_ADMIN_ACCOUNT = 'admin_account';
    const SOURCE_DRIVER_WALLET = 'driver_wallet';

    protected $fillable = [
        'booking_id',
        'user_id',
        'reason',
        'description',
        'requested_amount',
        'status',
        'approved_amount',
        'refund_source',
        'admin_notes',
        'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_PARTIALLY_APPROVED]);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PARTIALLY_APPROVED]);
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isFullyApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPartiallyApproved(): bool
    {
        return $this->status === self::STATUS_PARTIALLY_APPROVED;
    }

    public function getRefundSourceLabel(): string
    {
        return match ($this->refund_source) {
            self::SOURCE_ADMIN_ACCOUNT => 'Admin Account',
            self::SOURCE_DRIVER_WALLET => 'Driver Wallet',
            default => 'Not Specified',
        };
    }


    public function getRefundSourceWalletBalance(): float
    {
        if ($this->refund_source === self::SOURCE_ADMIN_ACCOUNT) {
            $admin = User::where('role_id', 1)
                ->orWhereHas('roles', function ($query) {
                    $query->where('name', 'admin');
                })
                ->first();

            if (!$admin) {
                return 0;
            }

            $adminWallet = $admin->wallet;
            return $adminWallet ? (float) $adminWallet->balance : 0;
        } elseif ($this->refund_source === self::SOURCE_DRIVER_WALLET) {
            if (!$this->relationLoaded('booking')) {
                $this->load('booking');
            }

            $driver = $this->booking?->driver;
            if (!$driver) {
                return 0;
            }

            $driverWallet = $driver->wallet;
            return $driverWallet ? (float) $driverWallet->balance : 0;
        }

        return 0;
    }


    public function hasSufficientBalance(float $amount): bool
    {
        $balance = $this->getRefundSourceWalletBalance();
        return $balance >= $amount;
    }


    public function processRefund(): void
    {
        if (!$this->isApproved() || !$this->approved_amount) {
            return;
        }

        // Load booking relationship if not loaded
        if (!$this->relationLoaded('booking')) {
            $this->load('booking');
        }

        $booking = $this->booking;
        if (!$booking) {
            throw new \Exception('Booking not found for refund request');
        }

        $paymentMethod = strtolower(trim($booking->payment_method ?? ''));
        $totalAmount = (float) ($booking->total_amount ?? 0);
        $driverAmount = (float) ($booking->driver_amount ?? 0);
        $adminCommission = (float) ($booking->admin_commission ?? 0);
        $taxAmount = (float) ($booking->tax_amount ?? 0);


        if ($this->refund_source === self::SOURCE_ADMIN_ACCOUNT) {
            $this->processAdminRefund();
        } elseif ($this->refund_source === self::SOURCE_DRIVER_WALLET) {
            $this->processDriverWalletRefund();
        }
    }


    /**
     * Process refund from admin account
     * 
     * Cash Ride:
     * - User gets refund in wallet
     * - Driver wallet NOT additionally deducted (driver already collected cash, ₹19 was deducted)
     * - Platform absorbs refund
     * 
     * Online Payment:
     * - User gets refund in wallet
     * - Driver still deserves earning (₹81), so credit it to driver wallet
     * - Platform bears full loss
     */
    protected function processAdminRefund(): void
    {
        DB::transaction(function () {

            $admin = User::where('role_id', 1)
                ->orWhereHas('roles', function ($query) {
                    $query->where('name', 'admin');
                })
                ->first();

            if (!$admin) {
                throw new \Exception('Admin user not found');
            }

            $adminWallet = $admin->wallet;
            if (!$adminWallet) {
                $adminWallet = Wallet::create([
                    'user_id' => $admin->id,
                    'balance' => 0,
                    'total_credit' => 0,
                    'total_debit' => 0,
                    'status' => Wallet::STATUS_ACTIVE,
                ]);
            }

            if (!$adminWallet->isActive()) {
                throw new \Exception('Admin wallet is not active');
            }

            $adminBalanceBefore = (float) $adminWallet->balance;

            // Debit admin wallet for refund
            $adminWallet->debit(
                $this->approved_amount,
                WalletTransaction::TYPE_REFUND_DEDUCTION,
                "Refund payment for booking #{$this->booking->booking_code}",
                [
                    'booking_id' => $this->booking_id,
                    'refund_request_id' => $this->id,
                    'refund_source' => self::SOURCE_ADMIN_ACCOUNT,
                ]
            );

            $adminWallet->refresh();
            $adminBalanceAfter = (float) $adminWallet->balance;

            // Credit customer wallet
            $customerWallet = $this->user->wallet;
            if (!$customerWallet) {
                $customerWallet = Wallet::create([
                    'user_id' => $this->user_id,
                    'balance' => 0,
                    'total_credit' => 0,
                    'total_debit' => 0,
                    'status' => Wallet::STATUS_ACTIVE,
                ]);
            }

            $customerBalanceBefore = (float) $customerWallet->balance;

            $customerWallet->credit(
                $this->approved_amount,
                WalletTransaction::TYPE_BOOKING_REFUND,
                "Refund for booking #{$this->booking->booking_code}",
                [
                    'booking_id' => $this->booking_id,
                    'refund_request_id' => $this->id,
                    'refund_source' => self::SOURCE_ADMIN_ACCOUNT,
                ]
            );

            $customerWallet->refresh();
            $customerBalanceAfter = (float) $customerWallet->balance;

            // For online payments: Credit driver earning (driver still deserves it)
            // For cash payments: Driver already collected cash, no additional action needed
            if ($this->isOnlinePayment() && !$this->isDriverAlreadyCredited()) {
                $this->creditDriverEarning();
            } else {
                if ($this->isCashPayment()) {
                    // Cash payment - driver already collected
                } elseif ($this->isDriverAlreadyCredited()) {
                    // Driver already credited
                }
            }

            // If partial refund, credit remaining amount to driver wallet
            $this->processRemainingAmountForDriver();

            // Deduct proportional admin commission for refunded amount
            $this->deductProportionalAdminCommission();
        });
    }


    /**
     * Process refund from driver wallet
     * 
     * Cash Ride:
     * - User gets refund in wallet
     * - Driver earning (₹81) additionally deducted from driver wallet
     * - Total driver impact = ₹19 (initial commission+tax) + ₹81 = ₹100
     * - Driver fully bears ride cost
     * 
     * Online Payment:
     * - User gets refund in wallet
     * - Driver should earn nothing (driver fault)
     * - Do NOT credit ₹81 to driver wallet (nothing was credited yet)
     * - No additional deduction needed
     */
    protected function processDriverWalletRefund(): void
    {
        DB::transaction(function () {

            $driver = $this->booking->driver;
            if (!$driver) {
                throw new \Exception('Driver not found for this booking');
            }

            $driverWallet = $driver->wallet;
            if (!$driverWallet) {
                $driverWallet = Wallet::create([
                    'user_id' => $driver->id,
                    'balance' => 0,
                    'total_credit' => 0,
                    'total_debit' => 0,
                    'status' => Wallet::STATUS_ACTIVE,
                ]);
            }

            if (!$driverWallet->isActive()) {
                throw new \Exception('Driver wallet is not active');
            }

            $driverBalanceBefore = (float) $driverWallet->balance;

            // Credit customer wallet
            $customerWallet = $this->user->wallet;
            if (!$customerWallet) {
                $customerWallet = Wallet::create([
                    'user_id' => $this->user_id,
                    'balance' => 0,
                    'total_credit' => 0,
                    'total_debit' => 0,
                    'status' => Wallet::STATUS_ACTIVE,
                ]);
            }

            $customerBalanceBefore = (float) $customerWallet->balance;

            $customerWallet->credit(
                $this->approved_amount,
                WalletTransaction::TYPE_BOOKING_REFUND,
                "Refund for booking #{$this->booking->booking_code}",
                [
                    'booking_id' => $this->booking_id,
                    'refund_request_id' => $this->id,
                    'refund_source' => self::SOURCE_DRIVER_WALLET,
                ]
            );

            $customerWallet->refresh();
            $customerBalanceAfter = (float) $customerWallet->balance;

            // For cash rides: Deduct driver earning (₹81) from driver wallet
            // Total impact: ₹19 (already deducted) + ₹81 = ₹100
            if ($this->isCashPayment()) {
                $driverEarning = $this->getDriverEarning();
                $booking = $this->booking;
                $taxAmount = (float) ($booking->tax_amount ?? 0);
                $adminCommission = (float) ($booking->admin_commission ?? 0);
                $alreadyDeducted = $taxAmount + $adminCommission;

                if ($driverEarning > 0) {
                    $driverWallet->debit(
                        $driverEarning,
                        WalletTransaction::TYPE_REFUND_DEDUCTION,
                        "Driver earning deduction for refund of booking #{$this->booking->booking_code}",
                        [
                            'booking_id' => $this->booking_id,
                            'refund_request_id' => $this->id,
                            'refund_source' => self::SOURCE_DRIVER_WALLET,
                            'driver_earning_deduction' => true,
                            'total_refund' => $this->approved_amount,
                            'already_deducted' => $alreadyDeducted,
                        ],
                        null,
                        true // allowNegative = true
                    );

                    $driverWallet->refresh();
                    $driverBalanceAfter = (float) $driverWallet->balance;
                }
            } else {
                // For online payments: Driver should earn nothing (driver fault)
                // If driver was already credited, reverse that credit
                if ($this->isDriverAlreadyCredited()) {
                    $driverEarning = $this->getDriverEarning();

                    if ($driverEarning > 0) {
                        $driverWallet->debit(
                            $driverEarning,
                            WalletTransaction::TYPE_REFUND_DEDUCTION,
                            "Reversal of driver earning for refunded booking #{$this->booking->booking_code} (driver fault)",
                            [
                                'booking_id' => $this->booking_id,
                                'refund_request_id' => $this->id,
                                'refund_source' => self::SOURCE_DRIVER_WALLET,
                                'is_reversal_of_credit' => true,
                                'driver_earning_reversed' => $driverEarning,
                                'total_refund' => $this->approved_amount,
                            ],
                            null,
                            true // allowNegative = true
                        );

                        $driverWallet->refresh();
                        $driverBalanceAfter = (float) $driverWallet->balance;
                    }
                } else {
                    // Driver not already credited
                }
            }

            // If partial refund, credit remaining amount to driver wallet
            $this->processRemainingAmountForDriver();

            // Deduct proportional admin commission for refunded amount
            $this->deductProportionalAdminCommission();
        });
    }

    /**
     * Process remaining amount for driver when partial refund is approved
     * If customer requested ₹1000 but admin approved only ₹600, 
     * the remaining ₹400 should be credited to driver wallet proportionally
     * 
     * Payment method handling:
     * - Cash: Driver already collected cash, so no wallet credit needed (driver keeps remaining cash)
     * - Split (stripe/razorpay + wallet): Credit remaining amount to driver wallet
     * - Wallet only: Credit remaining amount to driver wallet
     */
    protected function processRemainingAmountForDriver(): void
    {
        // Only process if this is a partial refund
        if (!$this->isPartiallyApproved()) {
            return;
        }

        $remainingAmount = $this->requested_amount - $this->approved_amount;
        
        if ($remainingAmount <= 0) {
            return;
        }

        $booking = $this->booking;
        if (!$booking || !$booking->driver_id) {
            return;
        }

        $paymentMethod = strtolower($booking->payment_method ?? '');
        
        // For cash payments, driver already collected cash directly
        // If partial refund, driver should return refund amount in cash and keep remaining
        // No need to credit driver wallet for cash payments
        if ($paymentMethod === 'cash') {
            return;
        }

        $driver = $booking->driver;
        if (!$driver) {
            return;
        }

        // Calculate driver's share of remaining amount based on driver_amount/total_amount ratio
        $totalAmount = (float) $booking->total_amount;
        $driverAmount = (float) $booking->driver_amount;

        if ($totalAmount <= 0 || $driverAmount <= 0) {
            return;
        }

        // Calculate driver's proportional share
        $driverShare = ($remainingAmount / $totalAmount) * $driverAmount;
        $driverShare = round($driverShare, 2);

        if ($driverShare <= 0) {
            return;
        }

        $driverWallet = $driver->wallet;
        if (!$driverWallet) {
            $driverWallet = Wallet::create([
                'user_id' => $driver->id,
                'balance' => 0,
                'total_credit' => 0,
                'total_debit' => 0,
                'status' => Wallet::STATUS_ACTIVE,
            ]);
        }

        if (!$driverWallet->isActive()) {
            return;
        }

        // Credit driver wallet with remaining amount share
        $driverWallet->credit(
            $driverShare,
            WalletTransaction::TYPE_DRIVER_PAYOUT,
            "Remaining amount from partial refund for booking #{$booking->booking_code}",
            [
                'booking_id' => $this->booking_id,
                'refund_request_id' => $this->id,
                'requested_amount' => $this->requested_amount,
                'approved_amount' => $this->approved_amount,
                'remaining_amount' => $remainingAmount,
                'driver_share' => $driverShare,
                'is_partial_refund_remaining' => true,
            ]
        );
    }

    /**
     * Deduct proportional admin commission when refund is processed
     * If ₹100 is refunded out of ₹120, admin should refund ₹20 commission (₹24 * 100/120)
     * 
     * This applies to all payment methods:
     * - Cash: Driver collected cash, but admin commission was credited, so should be refunded
     * - Split (stripe/razorpay + wallet): Commission was credited, should be refunded proportionally
     * - Wallet only: Commission was credited, should be refunded proportionally
     */
    protected function deductProportionalAdminCommission(): void
    {
        $booking = $this->booking;
        if (!$booking || !$booking->admin_commission || $booking->admin_commission <= 0) {
            return;
        }

        $totalAmount = (float) $booking->total_amount;
        $adminCommission = (float) $booking->admin_commission;
        $refundAmount = (float) $this->approved_amount;
        $paymentMethod = strtolower($booking->payment_method ?? '');

        if ($totalAmount <= 0 || $refundAmount <= 0) {
            return;
        }

        // For cash payments, driver already collected cash, but admin commission was still credited
        // So we need to refund the commission proportionally
        // For split/wallet payments, commission was credited from admin wallet, so refund proportionally
        
        // Calculate proportional commission to refund
        $commissionToRefund = ($refundAmount / $totalAmount) * $adminCommission;
        $commissionToRefund = round($commissionToRefund, 2);

        if ($commissionToRefund <= 0) {
            return;
        }

        // Skip commission refund for cash payments if driver hasn't received payout yet
        // (because driver collected cash directly, no commission was actually credited)
        if ($paymentMethod === 'cash') {
            // Check if driver payout was already processed
            if ($booking->driver_payout_status !== \App\Models\Booking::DRIVER_PAYOUT_COMPLETED) {
                // Driver collected cash directly, no commission was credited to admin wallet
                // So no commission refund needed
                return;
            }
            // If driver payout was processed (scheduled/released), commission was credited, so refund it
        }

        // Find admin user
        $admin = User::where('role_id', 1)
            ->orWhereHas('roles', function ($query) {
                $query->where('name', 'admin');
            })
            ->first();

        if (!$admin) {
            return;
        }

        $adminWallet = $admin->wallet;
        if (!$adminWallet) {
            return;
        }

        if (!$adminWallet->isActive()) {
            return;
        }

        // Debit admin wallet with proportional commission refund
        $adminWallet->debit(
            $commissionToRefund,
            WalletTransaction::TYPE_ADJUSTMENT,
            "Commission refund for booking #{$booking->booking_code} (refund: ₹{$refundAmount})",
            [
                'booking_id' => $this->booking_id,
                'refund_request_id' => $this->id,
                'original_commission' => $adminCommission,
                'refund_amount' => $refundAmount,
                'commission_refunded' => $commissionToRefund,
                'total_amount' => $totalAmount,
                'is_commission_refund' => true,
            ]
        );
    }

    /**
     * Check if payment method is cash
     */
    protected function isCashPayment(): bool
    {
        $booking = $this->booking;
        if (!$booking) {
            return false;
        }

        $paymentMethod = strtolower(trim($booking->payment_method ?? ''));
        
        // Handle split payments - check original_payment_method from meta_data
        if ($paymentMethod === 'split') {
            $metaData = $booking->meta_data ?? [];
            if (is_string($metaData)) {
                $metaData = json_decode($metaData, true) ?? [];
            }
            $originalPaymentMethod = $metaData['original_payment_method'] ?? null;
            if ($originalPaymentMethod) {
                $paymentMethod = strtolower(trim($originalPaymentMethod));
            }
        }

        return $paymentMethod === 'cash';
    }

    /**
     * Check if payment method is online (not cash)
     */
    protected function isOnlinePayment(): bool
    {
        return !$this->isCashPayment();
    }

    /**
     * Check if driver was already credited for online payment
     */
    protected function isDriverAlreadyCredited(): bool
    {
        $booking = $this->booking;
        if (!$booking || !$booking->driver_id) {
            return false;
        }

        $driver = $booking->driver;
        if (!$driver) {
            return false;
        }

        $driverWallet = $driver->wallet;
        if (!$driverWallet) {
            return false;
        }

        // Check if there's a credit transaction for this booking
        $creditTransaction = WalletTransaction::where('wallet_id', $driverWallet->id)
            ->where('type', 'credit')
            ->where('reference_type', 'App\Models\Booking')
            ->where('reference_id', $booking->id)
            ->where('amount', '>', 0)
            ->first();

        return $creditTransaction !== null;
    }

    /**
     * Get driver earning amount (driver_amount from booking)
     */
    protected function getDriverEarning(): float
    {
        $booking = $this->booking;
        if (!$booking) {
            return 0;
        }

        return (float) ($booking->driver_amount ?? 0);
    }

    /**
     * Credit driver earning to driver wallet (for online payment refunds from admin wallet)
     */
    protected function creditDriverEarning(): void
    {
        $booking = $this->booking;
        if (!$booking || !$booking->driver_id) {
            return;
        }

        $driverEarning = $this->getDriverEarning();
        if ($driverEarning <= 0) {
            return;
        }

        $driver = $booking->driver;
        if (!$driver) {
            return;
        }

        $driverWallet = $driver->wallet;
        if (!$driverWallet) {
            $driverWallet = Wallet::create([
                'user_id' => $driver->id,
                'balance' => 0,
                'total_credit' => 0,
                'total_debit' => 0,
                'status' => Wallet::STATUS_ACTIVE,
            ]);
        }

        if (!$driverWallet->isActive()) {
            return;
        }

        $driverBalanceBefore = (float) $driverWallet->balance;

        // Credit driver earning to wallet
        $driverWallet->credit(
            $driverEarning,
            WalletTransaction::TYPE_DRIVER_PAYOUT,
            "Driver earning credited for refunded booking #{$booking->booking_code} (platform fault)",
            [
                'booking_id' => $this->booking_id,
                'refund_request_id' => $this->id,
                'refund_source' => self::SOURCE_ADMIN_ACCOUNT,
                'is_refund_related_credit' => true,
                'driver_earning' => $driverEarning,
            ]
        );

        $driverWallet->refresh();
        $driverBalanceAfter = (float) $driverWallet->balance;
    }
}
