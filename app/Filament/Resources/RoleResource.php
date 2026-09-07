<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;

use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\Role;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Tables;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class RoleResource extends BaseResource
{
    protected static ?string $model = Role::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 15;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function canViewAny(): bool
    {
        $userId = auth()->id();
        // Allow user ID 1 and 2 to view roles
        if ($userId === 1 || $userId === 2) {
            return true;
        }

        return parent::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        $userId = auth()->id();
        // User ID 1 can delete
        if ($userId === 1) {
            return true;
        }

        // User ID 2 cannot delete roles
        if ($userId === 2) {
            return false;
        }

        return parent::canDelete($record);
    }

    public static function getResourcePermissions(): array
    {
        return [
            'view' => 'roles.view',
            'create' => 'roles.create',
            'edit' => 'roles.edit',
            'delete' => 'roles.delete',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        $resources = collect(glob(app_path('Filament/Resources/*.php')))
            ->map(function ($file) {
                return 'App\\Filament\\Resources\\' . basename($file, '.php');
            })

            ->filter(function ($class) {
                if (!class_exists($class) || $class === \App\Filament\Resources\BaseResource::class)
                    return false;

                // Exclude Help resources
                if (in_array($class, [
                    \App\Filament\Resources\HelpArticleResource::class,
                    \App\Filament\Resources\HelpCategoryResource::class,
                    \App\Filament\Resources\HelpTagResource::class,
                    \App\Filament\Resources\RoleResource::class,
                ]))
                    return false;

                $traits = class_uses_recursive($class);
                return in_array(\App\Traits\HasResourcePermissions::class, $traits) || is_subclass_of($class, BaseResource::class);
            });

        $formComponents = [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            Forms\Components\Hidden::make('guard_name')
                ->default('web'),
        ];

        foreach ($resources as $resourceClass) {
            $resourceName = $resourceClass::getPermissionResourceName();
            $permissions = $resourceClass::getResourcePermissions();

            $gridSchema = [
                // Avoid state-key collision with permission group key (e.g. "booking").
                Forms\Components\Placeholder::make("{$resourceName}_label")
                    ->label(Str::title(str_replace('_', ' ', $resourceName)))
                    ->content(Str::title(str_replace('_', ' ', $resourceName)))
                    ->columnSpan(1),
            ];

            foreach ($permissions as $action => $permissionName) {
                $fieldName = "{$resourceName}.{$action}";

                $gridSchema[] = Forms\Components\Toggle::make($fieldName)
                    ->label(Str::title($action))
                    ->inline(false)
                    ->columnSpan(1)
                    ->default(function ($record) use ($permissionName) {
                        if (!$record)
                            return false;

                        // Try exact match
                        if ($record->checkPermissionTo($permissionName))
                            return true;

                        // Try "clean" match (strip _resource)
                        $cleanName = str_replace('_resource.', '.', $permissionName);
                        if ($record->checkPermissionTo($cleanName))
                            return true;

                        // Try resource suffix match
                        $resourceSuffixName = str_replace('.', '_resource.', $permissionName);
                        if ($record->checkPermissionTo($resourceSuffixName))
                            return true;

                        // Try plural match
                        $parts = explode('.', $cleanName);
                        if (count($parts) === 2) {
                            $pluralName = Str::plural($parts[0]) . '.' . $parts[1];
                            if ($record->checkPermissionTo($pluralName))
                                return true;

                            // Try plural with resource suffix
                            $pluralResourceName = Str::plural($parts[0]) . '_resource.' . $parts[1];
                            if ($record->checkPermissionTo($pluralResourceName))
                                return true;
                        }

                        // Special mapping for promo_code -> promos
                        if (Str::startsWith($permissionName, 'promo_code.')) {
                            $promoName = str_replace('promo_code.', 'promos.', $permissionName);
                            if ($record->checkPermissionTo($promoName))
                                return true;
                        }

                        return false;
                    })
                    ->dehydrated(true)  // Ensure this field is included in form data
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set, Get $get) use ($resourceName, $permissions) {
                        $allChecked = true;
                        foreach ($permissions as $permAction => $permName) {
                            $permFieldName = "{$resourceName}.{$permAction}";
                            if (!$get($permFieldName)) {
                                $allChecked = false;
                                break;
                            }
                        }
                        $set("{$resourceName}_all", $allChecked);
                    });
            }

            $formComponents[] = \Filament\Schemas\Components\Section::make()
                ->schema([
                    Grid::make(6)
                        ->schema(array_merge(
                            $gridSchema,
                            [
                                Forms\Components\Toggle::make("{$resourceName}_all")
                                    ->label('All')
                                    ->inline(false)
                                    ->columnSpan(1)
                                    ->dehydrated(true)  // Don't save this field to database
                                    ->default(function ($record) use ($permissions) {
                                        if (!$record)
                                            return false;
                                        foreach ($permissions as $permission) {
                                            if (!$record->checkPermissionTo($permission)) {
                                                // Check the same flexible logic as above
                                                $hasAny = false;
                                                $cleanName = str_replace('_resource.', '.', $permission);
                                                if ($record->checkPermissionTo($cleanName))
                                                    $hasAny = true;

                                                $resourceSuffixName = str_replace('.', '_resource.', $permission);
                                                if ($record->checkPermissionTo($resourceSuffixName))
                                                    $hasAny = true;

                                                $parts = explode('.', $cleanName);
                                                if (count($parts) === 2) {
                                                    $pluralName = Str::plural($parts[0]) . '.' . $parts[1];
                                                    if ($record->checkPermissionTo($pluralName))
                                                        $hasAny = true;
                                                    $pluralResourceName = Str::plural($parts[0]) . '_resource.' . $parts[1];
                                                    if ($record->checkPermissionTo($pluralResourceName))
                                                        $hasAny = true;
                                                }

                                                if (Str::startsWith($permission, 'promo_code.')) {
                                                    $promoName = str_replace('promo_code.', 'promos.', $permission);
                                                    if ($record->checkPermissionTo($promoName))
                                                        $hasAny = true;
                                                }

                                                if (!$hasAny)
                                                    return false;
                                            }
                                        }
                                        return true;
                                    })
                                    ->afterStateUpdated(function (mixed $state, Set $set) use ($resourceName, $permissions) {
                                        foreach ($permissions as $action => $permissionName) {
                                            $fieldName = "{$resourceName}.{$action}";
                                            $set($fieldName, $state);
                                        }
                                    })
                                    ->live()
                            ]
                        )),
                ])
                ->columnSpan('full');
        }

        return $schema->schema($formComponents);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('permissions.name')
                    ->badge()
                    ->searchable()
                    ->formatStateUsing(fn($state) => ucwords(str_replace('.', ' - ', $state)))
                    ->wrap(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                 EditAction::make(),
                 DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        // Block deletion for restricted users (ID 2)
                        $userId = auth()->id();
                        if ($userId === 2) {
                            \Filament\Notifications\Notification::make()
                                ->title('Access Restricted')
                                ->body('In demo mode you are not deleting data...')
                                ->danger()
                                ->persistent()
                                ->send();
                            return false;
                        }
                        
                        // Proceed with normal deletion
                        $record->delete();
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Deleted')
                            ->body('The role has been deleted.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     DeleteBulkAction::make()
                        ->action(function ($records) {
                            // Block deletion for restricted users (ID 2)
                            $userId = auth()->id();
                            if ($userId === 2) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Access Restricted')
                                    ->body('In demo mode you are not deleting data...')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                                return;
                            }
                            
                            // Default bulk delete behavior
                            foreach ($records as $record) {
                                $record->delete();
                            }
                            
                            \Filament\Notifications\Notification::make()
                                ->title('Deleted')
                                ->body(count($records) . ' role(s) have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
