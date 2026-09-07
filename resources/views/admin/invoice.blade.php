<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #{{ $invoiceData['invoice_number'] }}</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .invoice-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .invoice-header h1 {
            margin: 0;
            font-size: 2.5em;
            font-weight: 300;
        }

        .invoice-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
        }

        .invoice-body {
            padding: 30px;
        }

        .invoice-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .info-section h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.2em;
            border-bottom: 2px solid #667eea;
            padding-bottom: 5px;
        }

        .info-section p {
            margin: 5px 0;
            color: #666;
        }

        .trip-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 30px;
        }

        .trip-details h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.2em;
        }

        .trip-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .fare-breakdown {
            margin-bottom: 30px;
        }

        .fare-breakdown h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.2em;
            border-bottom: 2px solid #667eea;
            padding-bottom: 5px;
        }

        .fare-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .fare-table th,
        .fare-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .fare-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        .fare-table .total-row {
            background-color: #667eea;
            color: white;
            font-weight: bold;
        }

        .payment-details {
            background: #e8f5e8;
            padding: 20px;
            border-radius: 6px;
            border-left: 4px solid #28a745;
        }

        .payment-details h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.2em;
        }

        .payment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .commission-info {
            background: #fff3cd;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #ffc107;
            margin-top: 15px;
        }

        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .print-btn:hover {
            background: #5a6fd8;
        }

        @media print {
            .print-btn {
                display: none;
            }

            body {
                background: white;
            }

            .invoice-container {
                box-shadow: none;
            }
        }
    </style>
</head>

<body>
    <button class="print-btn" onclick="window.print()">🖨️ Print Invoice</button>

    <div class="invoice-container">
        <div class="invoice-header">
            <h1>eTaxi</h1>
            <p>Taxi Booking Invoice</p>
            <p>Invoice #{{ $invoiceData['invoice_number'] }}</p>
        </div>

        <div class="invoice-body">
            <div class="invoice-info">
                <div class="info-section">
                    <h3>Customer Details</h3>
                    <p><strong>Name:</strong> {{ $invoiceData['customer']['name'] }}</p>
                    <p><strong>Phone:</strong> {{ $invoiceData['customer']['phone'] }}</p>
                    <p><strong>Email:</strong> {{ $invoiceData['customer']['email'] }}</p>
                </div>

                <div class="info-section">
                    <h3>Driver Details</h3>
                    <p><strong>Name:</strong> {{ $invoiceData['driver']['name'] }}</p>
                    <p><strong>Phone:</strong> {{ $invoiceData['driver']['phone'] }}</p>
                    <p><strong>Vehicle:</strong> {{ $invoiceData['driver']['vehicle'] }}</p>
                    <p><strong>License Plate:</strong> {{ $invoiceData['driver']['license_plate'] }}</p>
                </div>
            </div>

            <div class="trip-details">
                <h3>Trip Details</h3>
                <div class="trip-grid">
                    <div>
                        <p><strong>Pickup:</strong> {{ $invoiceData['trip_details']['pickup_address'] }}</p>
                        <p><strong>Dropoff:</strong> {{ $invoiceData['trip_details']['dropoff_address'] }}</p>
                    </div>
                    <div>
                        <p><strong>Distance:</strong> {{ $invoiceData['trip_details']['distance'] }}</p>
                        <p><strong>Duration:</strong> {{ $invoiceData['trip_details']['duration'] }}</p>
                        <p><strong>Started:</strong> {{ $invoiceData['trip_details']['started_at'] }}</p>
                        <p><strong>Completed:</strong> {{ $invoiceData['trip_details']['completed_at'] }}</p>
                    </div>
                </div>
            </div>

            <div class="fare-breakdown">
                <h3>Fare Breakdown</h3>
                <table class="fare-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Amount (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Base Fare</td>
                            <td>{{ number_format($invoiceData['fare_breakdown']['base_fare'], 2) }}</td>
                        </tr>
                        <tr>
                            <td>Distance Fare</td>
                            <td>{{ number_format($invoiceData['fare_breakdown']['distance_fare'], 2) }}</td>
                        </tr>
                        <tr>
                            <td>Time Fare</td>
                            <td>{{ number_format($invoiceData['fare_breakdown']['time_fare'], 2) }}</td>
                        </tr>
                        <tr>
                            <td>Waiting Charge</td>
                            <td>{{ number_format($invoiceData['fare_breakdown']['waiting_charge'], 2) }}</td>
                        </tr>
                        <tr>
                            <td>Night Charge</td>
                            <td>{{ number_format($invoiceData['fare_breakdown']['night_charge'], 2) }}</td>
                        </tr>
                        <tr>
                            <td>Surge Amount</td>
                            <td>{{ number_format($invoiceData['fare_breakdown']['surge_amount'], 2) }}</td>
                        </tr>
                        @if (($invoiceData['fare_breakdown']['additional_fare'] ?? 0) > 0)
                            <tr>
                                <td>Additional Fare</td>
                                <td>{{ number_format($invoiceData['fare_breakdown']['additional_fare'], 2) }}</td>
                            </tr>
                        @endif
                        @if (($invoiceData['fare_breakdown']['debt_amount'] ?? 0) > 0)
                            <tr>
                                <td>Outstanding Debt</td>
                                <td>{{ number_format($invoiceData['fare_breakdown']['debt_amount'], 2) }}</td>
                            </tr>
                        @endif
                        @php
                            $subtotalBeforeDiscount =
                                $invoiceData['fare_breakdown']['subtotal'] +
                                ($invoiceData['fare_breakdown']['discount_amount'] ?? 0);
                            $hasPromo =
                                !empty($invoiceData['fare_breakdown']['promo_code']) &&
                                ($invoiceData['fare_breakdown']['discount_amount'] ?? 0) > 0;
                        @endphp
                        <tr style="display: none;">
                            <td><strong>Subtotal</strong></td>
                            <td><strong>{{ number_format($subtotalBeforeDiscount, 2) }}</strong></td>
                        </tr>
                        <tr>
                            <td>Tax</td>
                            <td>{{ number_format($invoiceData['fare_breakdown']['tax_amount'], 2) }}</td>
                        </tr>
                        @if ($hasPromo)
                            <tr style="color: #28a745;">
                                <td>
                                    Promo Code: <strong>{{ $invoiceData['fare_breakdown']['promo_code'] }}</strong>
                                </td>
                                <td style="color: #28a745;">
                                    <strong>-{{ number_format($invoiceData['fare_breakdown']['discount_amount'], 2) }}</strong>
                                </td>
                            </tr>
                            <tr style="display: none;">
                                <td><strong>Subtotal After Discount</strong></td>
                                <td><strong>{{ number_format($invoiceData['fare_breakdown']['subtotal'], 2) }}</strong>
                                </td>
                            </tr>
                        @endif

                        @if (($invoiceData['fare_breakdown']['tip_amount'] ?? 0) > 0)
                            <tr style="color: #28a745;">
                                <td><strong>Tip</strong></td>
                                <td><strong>+{{ number_format($invoiceData['fare_breakdown']['tip_amount'], 2) }}</strong>
                                </td>
                            </tr>
                        @endif
                        <tr class="total-row">
                            <td><strong>Total Amount</strong></td>
                            <td><strong>₹{{ number_format($invoiceData['fare_breakdown']['total_amount'], 2) }}</strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="payment-details">
                <h3>Payment Details</h3>
                <div class="payment-grid">
                    <div>
                        <p><strong>Payment Method:</strong>
                            @if (isset($invoiceData['payment_details']['is_split_payment']) && $invoiceData['payment_details']['is_split_payment'])
                                Split (Wallet + {{ ucfirst($invoiceData['payment_details']['payment_method']) }})
                            @else
                                {{ ucfirst($invoiceData['payment_details']['payment_method']) }}
                            @endif
                        </p>
                        <p><strong>Payment Status:</strong>
                            {{ ucfirst($invoiceData['payment_details']['payment_status']) }}</p>
                        @if (isset($invoiceData['payment_details']['is_split_payment']) && $invoiceData['payment_details']['is_split_payment'])
                            <div style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                                <p style="margin: 5px 0;"><strong>Amount Paid from Wallet:</strong>
                                    ₹{{ number_format($invoiceData['payment_details']['wallet_amount'] ?? 0, 2) }}</p>
                                <p style="margin: 5px 0;"><strong>Amount Paid via
                                        {{ ucfirst($invoiceData['payment_details']['payment_method']) }}:</strong>
                                    ₹{{ number_format($invoiceData['payment_details']['online_paid_amount'] ?? 0, 2) }}
                                </p>
                                <p style="margin: 5px 0; font-weight: bold; color: #28a745;"><strong>Total
                                        Paid:</strong>
                                    ₹{{ number_format(($invoiceData['payment_details']['wallet_amount'] ?? 0) + ($invoiceData['payment_details']['online_paid_amount'] ?? 0), 2) }}
                                </p>
                            </div>
                        @endif
                    </div>
                    <div>
                        <p><strong>Driver Amount:</strong>
                            ₹{{ number_format($invoiceData['payment_details']['driver_amount'], 2) }}</p>
                        <p><strong>Platform Commission:</strong>
                            ₹{{ number_format($invoiceData['payment_details']['platform_commission'], 2) }}</p>
                    </div>
                </div>

                <div class="commission-info">
                    <p><strong>Commission Breakdown:</strong></p>
                    <p>Driver Commission: {{ $invoiceData['payment_details']['driver_commission_rate'] }}</p>
                    <p>Platform Commission: {{ $invoiceData['payment_details']['platform_commission_rate'] }}</p>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
