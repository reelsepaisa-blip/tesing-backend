<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function show(Booking $booking)
    {

        if (!auth()->check()) {
            abort(401, 'Unauthorized');
        }

        if ($booking->status !== 'completed') {
            abort(404, 'Invoice not available for incomplete bookings');
        }

        $booking->load(['user', 'driver.vehicles', 'promoUsage.promoCode']);

        $invoiceData = $this->generateInvoiceData($booking);

        return view('admin.invoice', compact('booking', 'invoiceData'));
    }

    private function generateInvoiceData(Booking $booking): array
    {
        $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT);

        $fareBreakdown = $this->calculateFareBreakdown($booking);

        $totalAmount = $fareBreakdown['total_amount'];

        return [
            'invoice_number' => $invoiceNumber,
            'booking_id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'invoice_date' => $booking->completed_at?->format('Y-m-d H:i:s'),
            'customer' => [
                'name' => $booking->user->name ?? '',
                'phone' => $booking->user->phone ?? '',
                'email' => $booking->user->email ?? '',
            ],
            'driver' => [
                'name' => $booking->driver->name ?? '',
                'phone' => $booking->driver->phone ?? '',
                'vehicle' => $booking->driver->vehicles->first()->model ?? 'N/A',
                'license_plate' => $booking->driver->vehicles->first()->registration_number ?? 'N/A',
            ],
            'trip_details' => [
                'pickup_address' => $booking->pickup_address,
                'dropoff_address' => $booking->dropoff_address,
                'distance' => ($booking->actual_distance ?? 0) . ' km',
                'duration' => ($booking->actual_duration ?? 0) . ' minutes',
                'started_at' => $booking->started_at?->format('Y-m-d H:i:s'),
                'completed_at' => $booking->completed_at?->format('Y-m-d H:i:s'),
            ],
            'fare_breakdown' => $fareBreakdown,
            'payment_details' => $this->generatePaymentDetails($booking, $fareBreakdown),
        ];
    }


    private function calculateFareBreakdown(Booking $booking): array
    {
        $baseFare = (float) ($booking->base_fare ?? 0);
        $distanceFare = (float) ($booking->distance_fare ?? 0);
        $timeFare = (float) ($booking->time_fare ?? 0);
        $waitingCharge = (float) ($booking->waiting_charge ?? 0);
        $nightCharge = (float) ($booking->night_charge ?? 0);
        $surgeAmount = (float) ($booking->surge_amount ?? 0);
        $discountAmount = (float) ($booking->discount_amount ?? 0);
        $taxAmount = (float) ($booking->tax_amount ?? 0);
        $debtAmount = (float) ($booking->debt_amount ?? 0);
        $tipAmount = (float) ($booking->tip_amount ?? 0);
        $driverAmount = (float) ($booking->driver_amount ?? 0);
        $adminCommission = (float) ($booking->admin_commission ?? 0);

        $knownFareSum = $baseFare + $distanceFare + $timeFare + $waitingCharge + $nightCharge + $surgeAmount;

        $subtotal = (float) ($booking->subtotal ?? 0);

        // Calculate subtotal if not set
        if ($subtotal <= 0) {
            $subtotal = $knownFareSum;
        }

        $additionalFare = max(0, $subtotal - $knownFareSum - $debtAmount);

        // Calculate total amount ensuring tip is included
        // Formula: (subtotal after discount + debt) + tax + tip
        $subtotalAfterDiscount = $subtotal - $discountAmount;
        if ($subtotalAfterDiscount < 0) {
            $subtotalAfterDiscount = 0;
        }

        // Add debt to subtotal after discount (debt is added before tax calculation)
        $subtotalAfterDiscountAndDebt = $subtotalAfterDiscount + $debtAmount;

        // Calculate total: subtotal (after discount + debt) + tax + tip
        $totalAmount = $subtotalAfterDiscountAndDebt + $taxAmount + $tipAmount;

        // Use booking total_amount if it exists and is valid (should already include tip)
        $bookingTotalAmount = (float) ($booking->total_amount ?? 0);
        if ($bookingTotalAmount > 0 && abs($bookingTotalAmount - $totalAmount) < 1.0) {
            // Booking total is close to calculated total, use it
            $totalAmount = $bookingTotalAmount;
        }

        return [
            'base_fare' => $baseFare,
            'distance_fare' => $distanceFare,
            'time_fare' => $timeFare,
            'waiting_charge' => $waitingCharge,
            'night_charge' => $nightCharge,
            'surge_amount' => $surgeAmount,
            'additional_fare' => $additionalFare,
            'debt_amount' => $debtAmount,
            'tip_amount' => $tipAmount,
            'subtotal' => $subtotal,
            'promo_code' => $booking->promo_code,
            'promo_description' => $booking->promoUsage?->promoCode?->description ?? null,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
        ];
    }


    private function calculateDriverCommissionRate(Booking $booking, array $fareBreakdown): string
    {
        $adminCommissionRate = (float) ($booking->admin_commission_rate ?? 0);

        $driverCommissionRate = 100 - $adminCommissionRate;

        return round($driverCommissionRate, 1) . '%';
    }


    private function calculatePlatformCommissionRate(Booking $booking, array $fareBreakdown): string
    {
        $adminCommissionRate = (float) ($booking->admin_commission_rate ?? 0);

        return round($adminCommissionRate, 1) . '%';
    }

    private function generatePaymentDetails(Booking $booking, array $fareBreakdown): array
    {
        $walletAmount = (float) ($booking->wallet_amount ?? 0);
        $onlinePaidAmount = (float) ($booking->online_paid_amount ?? 0);
        $isSplitPayment = $walletAmount > 0 && $onlinePaidAmount > 0;

        // Get original payment method from meta_data if it's a split payment
        $originalPaymentMethod = '';
        if ($booking->payment_method === 'split') {
            $metaData = $booking->meta_data ?? [];
            $originalPaymentMethod = $metaData['original_payment_method'] ?? '';
        }

        // For split payments, use original payment method for display, otherwise use booking payment_method
        $displayPaymentMethod = $isSplitPayment && $originalPaymentMethod
            ? $originalPaymentMethod
            : ($booking->payment_method ?? '');

        return [
            'payment_method' => $displayPaymentMethod,
            'payment_status' => $booking->payment_status ?? '',
            'driver_amount' => $booking->driver_amount ?? 0,
            'platform_commission' => $booking->admin_commission ?? 0,
            'driver_commission_rate' => $this->calculateDriverCommissionRate($booking, $fareBreakdown),
            'platform_commission_rate' => $this->calculatePlatformCommissionRate($booking, $fareBreakdown),
            'wallet_amount' => $walletAmount,
            'online_paid_amount' => $onlinePaidAmount,
            'is_split_payment' => $isSplitPayment,
        ];
    }
}
