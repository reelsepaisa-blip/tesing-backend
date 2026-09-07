<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\UserDebt;


class UserDebtService
{
    public function recordCancellationDebt(Booking $booking, ?string $reason = null): void
    {
        if (!$booking->user_id) {
            return;
        }

        if ($booking->payment_status === 'paid') {
            return;
        }

        // If driver cancelled, don't add cancellation charge to customer's debt
        // The driver should pay the cancellation charge, not the customer
        if (
            $booking->driver_id && $booking->cancelled_by_id &&
            (int) $booking->cancelled_by_id === (int) $booking->driver_id
        ) {
            return;
        }

        // For cancellation debts, prioritize cancellation_charge or debt_amount over total_amount
        // because total_amount might be 0 or contain trip amount, not cancellation charge
        $amount = null;

        // If cancellation_charge is NULL but booking is cancelled, try to get from debt_amount or recalculate
        if ($booking->cancellation_charge !== null) {
            $amount = (float) $booking->cancellation_charge;
        } elseif ($booking->debt_amount !== null && $booking->debt_amount > 0) {
            $amount = (float) $booking->debt_amount;
        } elseif (in_array($booking->status, ['cancelled', 'Cancelled', \App\Enums\BookingState::CANCELLED->value], true)) {
            // If cancellation_charge is NULL but booking is cancelled, 
            // it might not have been calculated - don't proceed if charge was never calculated
            return;
        } else {
            $amount = (float) ($booking->total_amount ?? 0);
        }

        if ($amount <= 0) {
            return;
        }

        $currency = $this->determineCurrency($booking);

        $debt = UserDebt::firstOrNew([
            'user_id' => $booking->user_id,
            'original_booking_id' => $booking->id,
            'type' => 'cancellation_fee',
        ]);

        if ($debt->exists && $debt->status === UserDebt::STATUS_SETTLED) {
            return;
        }

        $debt->fill([
            'amount' => $amount,
            'currency' => $currency,
            'description' => $reason,
            'meta_data' => [
                'cancellation_charge' => $booking->cancellation_charge,
                'tax_amount' => $booking->tax_amount,
            ],
        ]);

        if (!$debt->due_at) {
            $debt->due_at = now();
        }

        if (!$debt->exists) {
            $debt->status = UserDebt::STATUS_PENDING;
        }

        $debt->save();
    }

    public function settleAppliedDebtsForBooking(Booking $booking): void
    {
        UserDebt::settleForBooking($booking);
    }

    public function releaseAppliedDebtsForBooking(Booking $booking): void
    {
        if (!$booking->user) {
            return;
        }

        $booking->user->debts()
            ->where('status', UserDebt::STATUS_APPLIED)
            ->where('applied_booking_id', $booking->id)
            ->update([
                'status' => UserDebt::STATUS_PENDING,
                'applied_booking_id' => null,
                'applied_at' => null,
            ]);
    }

    protected function determineCurrency(Booking $booking): string
    {
        if (isset($booking->currency) && $booking->currency) {
            return $booking->currency;
        }

        $city = $booking->relationLoaded('city') ? $booking->city : $booking->city()->first();
        if ($city && $city->currency) {
            return $city->currency;
        }

        $rideType = $booking->relationLoaded('rideType') ? $booking->rideType : $booking->rideType()->first();
        if ($rideType && $rideType->currency) {
            return $rideType->currency;
        }

        return config('app.currency', 'INR');
    }
}
