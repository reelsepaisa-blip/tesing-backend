<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory, SoftDeletes, \App\Traits\PreventsDemoDeletion;

    protected $table = 'transactions';

    protected $fillable = [
        'transaction_id',
        'wallet_id',
        'user_id',
        'booking_id',
        'type',
        'amount',
        'balance',
        'description',
        'status',
        'payment_method',
        'payment_details',
        'reference_id',
        'reference_type',
        'meta_data',
        'currency',
        'gateway_transaction_id',
        'gateway_response',
        'processed_at',
        'failed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'payment_details' => 'array',
        'meta_data' => 'array',
        'gateway_response' => 'array',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
