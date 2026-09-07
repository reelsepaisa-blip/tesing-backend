<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Group;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\KeyValue;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\BulkAction;
use App\Filament\Resources\DriverDocumentResource\Pages\ListDriverDocuments;
use App\Filament\Resources\DriverDocumentResource\Pages\CreateDriverDocument;
use App\Filament\Resources\DriverDocumentResource\Pages\EditDriverDocument;
use App\Filament\Resources\DriverDocumentResource\Pages;
use App\Filament\Resources\DriverDocumentResource\RelationManagers;
use App\Models\DriverDocument;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;

class DriverDocumentResource extends Resource
{
    protected static ?string $model = DriverDocument::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'Driver Management';

    protected static ?int $navigationSort = 100;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }


    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Driver Information')
                    ->schema([
                        Select::make('driver_id')
                            ->relationship('driver', 'name')
                            ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('email')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->unique('users', 'email'),
                                TextInput::make('phone')
                                    ->required()
                                    ->maxLength(20)
                                    ->unique('users', 'phone'),
                                TextInput::make('password')
                                    ->password()
                                    ->required()
                                    ->minLength(8),
                            ]),
                    ]),

                Section::make('Document Information')
                    ->schema([
                        Select::make('type')
                            ->label('Document Type')
                            ->options([
                                'license' => 'Driving License',
                                'aadhar' => 'Aadhar Card',
                                'pan' => 'PAN Card',
                                'police_verification' => 'Police Verification',
                                'address_proof' => 'Address Proof',
                                'driving_license' => 'Driving License',
                                'identity_proof' => 'Identity Proof',
                                'profile_photo' => 'Profile Photo',
                                'aadhar_card' => 'Aadhar Card',
                                'pan_card' => 'PAN Card',
                                'voter_id' => 'Voter ID',
                                'national_id' => 'National ID',
                                'vehicle_rc' => 'Vehicle RC',
                                'vehicle_insurance' => 'Vehicle Insurance',
                                'vehicle_permit' => 'Vehicle Permit',
                                'vehicle_fitness' => 'Vehicle Fitness',
                                'vehicle_photo' => 'Vehicle Photo',
                                'vehicle_registration' => 'Vehicle Registration',
                                'registration_certificate' => 'Registration Certificate',
                                'fitness_certificate' => 'Fitness Certificate',
                                'other' => 'Other',
                            ])
                            ->required(),
                        TextInput::make('number')
                            ->label('Document Number')
                            ->required()
                            ->maxLength(255),
                        FileUpload::make('file_front')
                            ->label('Front Side')
                            ->directory('driver-documents')
                            ->acceptedFileTypes(['application/pdf', 'image/*'])
                            ->maxSize(5120),
                        FileUpload::make('file_back')
                            ->label('Back Side')
                            ->directory('driver-documents')
                            ->acceptedFileTypes(['application/pdf', 'image/*'])
                            ->maxSize(5120),
                        DatePicker::make('expiry_date')
                            ->required(),
                    ])->columns(2),

                Section::make('Verification')
                    ->schema([
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])
                            ->required(),
                        Textarea::make('rejection_reason')
                            ->maxLength(65535)
                            ->visible(fn(Get $get) => $get('status') === 'rejected'),
                    ])->columns(2),

                Section::make('Additional Information')
                    ->schema([
                        KeyValue::make('meta_data')
                            ->keyLabel('Field')
                            ->valueLabel('Value')
                            ->addable()
                            ->deletable(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('driver.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Document Type')
                    ->formatStateUsing(function ($state) {
                        $types = [
                            'license' => 'Driving License',
                            'aadhar' => 'Aadhar Card',
                            'pan' => 'PAN Card',
                            'police_verification' => 'Police Verification',
                            'address_proof' => 'Address Proof',
                            'driving_license' => 'Driving License',
                            'identity_proof' => 'Identity Proof',
                            'profile_photo' => 'Profile Photo',
                            'aadhar_card' => 'Aadhar Card',
                            'pan_card' => 'PAN Card',
                            'voter_id' => 'Voter ID',
                            'national_id' => 'National ID',
                            'vehicle_rc' => 'Vehicle RC',
                            'vehicle_insurance' => 'Vehicle Insurance',
                            'vehicle_permit' => 'Vehicle Permit',
                            'vehicle_fitness' => 'Vehicle Fitness',
                            'vehicle_photo' => 'Vehicle Photo',
                            'vehicle_registration' => 'Vehicle Registration',
                            'registration_certificate' => 'Registration Certificate',
                            'fitness_certificate' => 'Fitness Certificate',
                            'other' => 'Other',
                        ];
                        return $types[$state] ?? ucfirst(str_replace('_', ' ', $state));
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Document Number')
                    ->searchable()
                    ->sortable(),
                ImageColumn::make('file_front')
                    ->label('Front Side')
                    ->size(60)
                    ->circular()
                    ->openUrlInNewTab()
                    ->extraImgAttributes(fn($record) => [
                        'class' => 'cursor-pointer',
                        'onclick' => $record && $record->file_front ? "openImageModal('" . asset('storage/' . $record->file_front) . "', 'Front Side')" : "",
                    ])
                    ->visible(fn($record) => $record && $record->file_front && in_array(pathinfo($record->file_front, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'png', 'gif', 'webp'])),
                ImageColumn::make('file_back')
                    ->label('Back Side')
                    ->size(60)
                    ->circular()
                    ->openUrlInNewTab()
                    ->extraImgAttributes(fn($record) => [
                        'class' => 'cursor-pointer',
                        'onclick' => $record && $record->file_back ? "openImageModal('" . asset('storage/' . $record->file_back) . "', 'Back Side')" : "",
                    ])
                    ->visible(fn($record) => $record && $record->file_back && in_array(pathinfo($record->file_back, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'png', 'gif', 'webp'])),
                TextColumn::make('expiry_date')
                    ->date()
                    ->sortable()
                    ->color(
                        fn($record): string =>
                        $record && $record->expiry_date ?
                            ($record->expiry_date < now() ? 'danger' : ($record->expiry_date < now()->addMonth() ? 'warning' : 'success'))
                            : 'gray'
                    ),
                SelectColumn::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->sortable(),
                TextColumn::make('verifiedBy.name')
                    ->label('Verified By')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('verified_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('driver')
                    ->relationship('driver', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown Driver')
                    ->searchable()
                    ->preload()
                    ->multiple(),
                SelectFilter::make('type')
                    ->label('Document Type')
                    ->options([
                        'license' => 'Driving License',
                        'aadhar' => 'Aadhar Card',
                        'pan' => 'PAN Card',
                        'police_verification' => 'Police Verification',
                        'address_proof' => 'Address Proof',
                        'driving_license' => 'Driving License',
                        'identity_proof' => 'Identity Proof',
                        'profile_photo' => 'Profile Photo',
                        'aadhar_card' => 'Aadhar Card',
                        'pan_card' => 'PAN Card',
                        'voter_id' => 'Voter ID',
                        'national_id' => 'National ID',
                        'vehicle_rc' => 'Vehicle RC',
                        'vehicle_insurance' => 'Vehicle Insurance',
                        'vehicle_permit' => 'Vehicle Permit',
                        'vehicle_fitness' => 'Vehicle Fitness',
                        'vehicle_photo' => 'Vehicle Photo',
                        'vehicle_registration' => 'Vehicle Registration',
                        'registration_certificate' => 'Registration Certificate',
                        'fitness_certificate' => 'Fitness Certificate',
                        'other' => 'Other',
                    ])
                    ->multiple(),
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->multiple(),
                Filter::make('expiring')
                    ->query(fn(Builder $query): Builder => $query->where('expiry_date', '<=', now()->addMonth())),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
                Action::make('view_front')
                    ->label('View Front')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->visible(fn($record) => $record && $record->file_front && in_array(pathinfo($record->file_front, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'png', 'gif', 'webp']))
                    ->url(fn($record) => $record && $record->file_front ? asset('storage/' . $record->file_front) : '#')
                    ->openUrlInNewTab(),
                Action::make('view_back')
                    ->label('View Back')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->visible(fn($record) => $record && $record->file_back && in_array(pathinfo($record->file_back, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'png', 'gif', 'webp']))
                    ->url(fn($record) => $record && $record->file_back ? asset('storage/' . $record->file_back) : '#')
                    ->openUrlInNewTab(),
                Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn($record): bool => $record && $record->status === 'pending')
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'approved',
                            'verified_by' => Filament::auth()->id(),
                            'verified_at' => now(),
                        ]);
                    }),
                Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->required()
                            ->maxLength(65535),
                    ])
                    ->visible(fn($record): bool => $record && $record->status === 'pending')
                    ->action(function ($record, array $data) {
                        $record->update([
                            'status' => 'rejected',
                            'rejection_reason' => $data['rejection_reason'],
                            'verified_by' => Filament::auth()->id(),
                            'verified_at' => now(),
                        ]);
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('approve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn(Collection $records) => $records->each(fn($record) => $record->update([
                            'status' => 'approved',
                            'verified_by' => Filament::auth()->id(),
                            'verified_at' => now(),
                        ])))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDriverDocuments::route('/'),
            'create' => CreateDriverDocument::route('/create'),
            'edit' => EditDriverDocument::route('/{record}/edit'),
        ];
    }

    public static function getWidgets(): array
    {
        return [

        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ? 'warning' : null;
    }
}
