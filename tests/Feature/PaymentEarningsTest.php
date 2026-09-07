<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentEarningsTest extends TestCase
{
    use RefreshDatabase;

    protected $driver;
    protected $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a driver
        $this->driver = User::factory()->create([
            'role_id' => 2,  // Assuming 2 is driver role
            'is_online' => 1,
            'is_verified' => 1
        ]);

        // Create wallet for driver
        $this->wallet = Wallet::create([
            'user_id' => $this->driver->id,
            'balance' => 0,
            'status' => 'active'
        ]);

        // Assign wallet to driver relation if needed, though usually handled by relationship
    }

    public function test_get_earnings_list_filters_by_amount_range()
    {
        // Create bookings with different amounts
        $this->createBookingWithAmount(10);
        $this->createBookingWithAmount(30);
        $this->createBookingWithAmount(60);

        // Authenticate as driver
        $this->actingAs($this->driver);

        // Test range 25-50
        $response = $this->getJson('/api/payments/driver/earnings/list?amount_min=25&amount_max=50');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $earnings = $response->json('data.earnings');
        $transactions = [];
        foreach ($earnings as $day) {
            foreach ($day['transactions'] as $tx) {
                $transactions[] = $tx;
            }
        }

        // Should only have the booking with amount 30
        $this->assertCount(1, $transactions);
        $this->assertEquals(30, $transactions[0]['amount']);
    }

    private function createBookingWithAmount($amount)
    {
        $booking = Booking::create([
            'booking_code' => 'BK' . rand(1000, 9999),
            'user_id' => User::factory()->create()->id,
            'driver_id' => $this->driver->id,
            'driver_amount' => $amount,
            'total_amount' => $amount + 10,
            'status' => 'completed',
            'payment_method' => 'cash',
            'completed_at' => Carbon::now(),
            'started_at' => Carbon::now()->subMinutes(20),
        ]);

        return $booking;
    }
}
