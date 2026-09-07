<?php

namespace App\Auth;

use Closure;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class CaseSensitiveUserProvider extends EloquentUserProvider
{
    
    public function retrieveByCredentials(array $credentials)
    {
        if (
            empty($credentials) ||
            (count($credentials) === 1 &&
                str_contains($this->firstCredentialKey($credentials), 'password'))
        ) {
            return null;
        }

        $originalEmail = isset($credentials['email']) ? $credentials['email'] : null;

        if (isset($credentials['email'])) {
            $credentials['email'] = strtolower(trim($credentials['email']));
        }

        $query = $this->newModelQuery();

        foreach ($credentials as $key => $value) {
            if (str_contains($key, 'password')) {
                continue;
            }

            if (is_array($value) || $value instanceof Closure) {
                $query->where($key, $value);
            } else {
                $query->where($key, $value);
            }
        }

        $user = $query->first();

        if ($user && $originalEmail !== null) {


            $storedEmail = $user->email;
            $normalizedOriginal = strtolower(trim($originalEmail));


            if ($originalEmail !== $normalizedOriginal) {
                return null; // Reject case-insensitive attempts
            }
        }

        return $user;
    }

    
    protected function firstCredentialKey(array $credentials)
    {
        foreach ($credentials as $key => $value) {
            return $key;
        }

        return null;
    }
}
