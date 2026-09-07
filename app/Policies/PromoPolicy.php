<?php

namespace App\Policies;

use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PromoPolicy
{

    public function viewAny(User $user): bool
    {
        return in_array((int) $user->role_id, [1, 4]); // Admin or Support
    }


    public function view(User $user, PromoCode $promoCode): bool
    {

        if (in_array((int) $user->role_id, [1, 4])) {
            return true;
        }

        return $user->promoUsages()
            ->where('promo_code_id', $promoCode->id)
            ->exists();
    }


    public function create(User $user): bool
    {
        return (int) $user->role_id === 1;
    }


    public function update(User $user, PromoCode $promoCode): bool
    {
        return (int) $user->role_id === 1;
    }


    public function delete(User $user, PromoCode $promoCode): bool
    {
        return (int) $user->role_id === 1;
    }


    public function restore(User $user, PromoCode $promoCode): bool
    {
        return (int) $user->role_id === 1;
    }


    public function forceDelete(User $user, PromoCode $promoCode): bool
    {
        return false; // Never allow permanent deletion
    }


    public function validate(User $user, PromoCode $promoCode): bool
    {

        if (
            $promoCode->status !== 'active' ||
            ($promoCode->starts_at && now() < $promoCode->starts_at) ||
            ($promoCode->expires_at && now() > $promoCode->expires_at)
        ) {
            return false;
        }

        if ($promoCode->max_uses_total && $promoCode->usages()->count() >= $promoCode->max_uses_total) {
            return false;
        }

        if ($promoCode->max_uses_per_user) {
            $userUsages = $promoCode->usages()
                ->where('user_id', $user->id)
                ->count();

            if ($userUsages >= $promoCode->max_uses_per_user) {
                return false;
            }
        }

        if ($promoCode->is_first_ride_only && $user->bookings()->exists()) {
            return false;
        }

        return true;
    }
}
