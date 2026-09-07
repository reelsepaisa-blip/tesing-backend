<?php

namespace App\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;

use App\Traits\HasDemoMode;
use App\Models\User;
use App\Models\City;
use App\Models\Zone;
use App\Models\PromoCode;
use App\Models\CancellationPolicy;
use App\Models\Wallet;
use App\Traits\HasResourcePermissions;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

abstract class BaseResource extends Resource
{
    use HasResourcePermissions;
    use HasDemoMode;

    protected static bool $isGloballySearchable = true;

    public static function canViewAny(): bool
    {
        $userId = auth()->id();
        
        // User ID 1 and 2 can see all resources
        if ($userId === 1 || $userId === 2) {
            return true;
        }

        return static::checkPermission('view');
    }

    public static function canCreate(): bool
    {
        $userId = auth()->id();

        // User ID 1 can create
        if ($userId === 1) {
            return true;
        }

        // User ID 2 can create (but cannot delete)
        if ($userId === 2) {
            return true;
        }

        return static::checkPermission('create');
    }

    public static function canEdit(Model $record): bool
    {
        $userId = auth()->id();

        // User ID 1 can edit everything
        if ($userId === 1) {
            return true;
        }

        // User ID 2 can edit, but not preserved data (data that wasn't deleted during reset)
        if ($userId === 2) {
            // Check if this record is preserved data that should not be editable
            if (static::isPreservedData($record)) {
                return false;
            }
            return true;
        }

        return static::checkPermission('edit');
    }

    public static function canDelete(Model $record): bool
    {
        $userId = auth()->id();

        // User ID 1 can delete
        if ($userId === 1) {
            return true;
        }

        // User ID 2 can see delete button but deletion will be prevented with message
        if ($userId === 2) {
            return true;
        }

        return static::checkPermission('delete');
    }

    protected static function checkPermission(string $action): bool
    {
        $permissions = static::getResourcePermissions();
        $permissionName = $permissions[$action] ?? null;

        if (!$permissionName) {
            return false;
        }

        $cacheKey = 'user_permission_' . auth()->id() . '_' . $permissionName;

        return Cache::remember($cacheKey, now()->addMinutes(60), function () use ($permissionName) {
            return auth()->user()->hasPermissionTo($permissionName);
        });
    }

    public static function clearPermissionCache(): void
    {
        $permissions = static::getResourcePermissions();
        foreach ($permissions as $permission) {
            Cache::forget('user_permission_' . auth()->id() . '_' . $permission);
        }
    }

    /** Clear permission cache for a specific user (e.g. after role change). */
    public static function clearPermissionCacheForUser(int $userId): void
    {
        $permissionNames = \Spatie\Permission\Models\Permission::where('guard_name', 'web')->pluck('name');
        foreach ($permissionNames as $name) {
            Cache::forget('user_permission_' . $userId . '_' . $name);
        }
    }


    public static function canGloballySearch(): bool
    {

        if (!static::shouldRegisterNavigation()) {
            return false;
        }

        $searchableAttributes = static::getGloballySearchableAttributes();
        if (empty($searchableAttributes)) {
            return false;
        }

        return static::canAccess();
    }


    public static function getGloballySearchableAttributes(): array
    {

        $titleAttribute = static::getRecordTitleAttribute();

        if ($titleAttribute !== null) {
            return [$titleAttribute];
        }

        return ['id'];
    }

    /**
     * Mask data for user id 2 (demo mode)
     */
    protected static function maskData($value): string
    {
        if (auth()->id() === 2) {
            return 'xxx';
        }
        return $value;
    }

    /**
     * Mask money/currency data for user id 2
     */
    protected static function maskMoney($value): string
    {
        if (auth()->id() === 2) {
            return 'xxx';
        }
        return $value;
    }

    /**
     * Check if a record is preserved data (not deleted during reset)
     * User ID 2 should not be able to edit preserved data
     */
    protected static function isPreservedData(Model $record): bool
    {
        $modelClass = get_class($record);
        $recordId = $record->id ?? null;

        if (!$recordId) {
            return false;
        }

        // Define preserved IDs based on reset data logic
        $preservedData = [
            // Users: IDs 1, 2, 3, 4, 5, 6, 7
            User::class => [1, 2, 3, 4, 5, 6, 7],
            
            // Cities: ID 1
            City::class => [1],
            
            // Zones: ID 1
            Zone::class => [1],
            
            // Promo codes: IDs 1, 2
            PromoCode::class => [1, 2],
            
            // Cancellation policies: IDs 1, 2, 3, 4, 5, 6, 7
            CancellationPolicy::class => [1, 2, 3, 4, 5, 6, 7],
        ];

        // Check if this model class has preserved data
        if (isset($preservedData[$modelClass])) {
            return in_array($recordId, $preservedData[$modelClass]);
        }

        // For Wallet model, check user_id instead of id
        if ($modelClass === Wallet::class) {
            $userId = $record->user_id ?? null;
            if ($userId) {
                return in_array($userId, [1, 2, 3, 4, 5, 6, 7]);
            }
        }

        return false;
    }
}
