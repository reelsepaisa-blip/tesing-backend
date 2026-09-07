<?php

namespace App\Filament\Resources\VehicleResource\RelationManagers;

use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $recordTitleAttribute = 'document_type';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Document Information')
                    ->schema([
                        Forms\Components\Select::make('document_type')
                            ->options([
                                'registration' => 'Registration Certificate',
                                'insurance' => 'Insurance Policy',
                                'permit' => 'Vehicle Permit',
                                'fitness' => 'Fitness Certificate',
                                'pollution' => 'Pollution Certificate',
                                'other' => 'Other',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('document_number')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\FileUpload::make('document_url')
                            ->required()
                            ->directory('vehicle-documents')
                            ->acceptedFileTypes(['application/pdf', 'image/*'])
                            ->maxSize(5120),
                        Forms\Components\DatePicker::make('expiry_date')
                            ->required(),
                    ])->columns(2),

                Section::make('Verification')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])
                            ->required(),
                        Forms\Components\Textarea::make('rejection_reason')
                            ->maxLength(65535)
                            ->visible(fn (Get $get) => $get('status') === 'rejected'),
                    ])->columns(2),

                Section::make('Additional Information')
                    ->schema([
                        Forms\Components\KeyValue::make('meta_data')
                            ->keyLabel('Field')
                            ->valueLabel('Value')
                            ->addable()
                            ->deletable(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('document_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('expiry_date')
                    ->date()
                    ->sortable()
                    ->color(fn ($record): string => 
                        $record->expiry_date < now() ? 'danger' :
                        ($record->expiry_date < now()->addMonth() ? 'warning' : 'success')
                    ),
                Tables\Columns\SelectColumn::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('verified_by')
                    ->searchable(),
                Tables\Columns\TextColumn::make('verified_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('document_type')
                    ->options([
                        'registration' => 'Registration Certificate',
                        'insurance' => 'Insurance Policy',
                        'permit' => 'Vehicle Permit',
                        'fitness' => 'Fitness Certificate',
                        'pollution' => 'Pollution Certificate',
                        'other' => 'Other',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
                Tables\Filters\Filter::make('expiring')
                    ->query(fn (Builder $query): Builder => $query->where('expiry_date', '<=', now()->addMonth())),
            ])
            ->headerActions([
                 CreateAction::make(),
            ])
            ->actions([
                 EditAction::make(),
                 DeleteAction::make(),
                 Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => $record->status === 'pending')
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'approved',
                            'verified_by' => auth()->id(),
                            'verified_at' => now(),
                        ]);
                    }),
                 Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->required()
                            ->maxLength(65535),
                    ])
                    ->visible(fn ($record): bool => $record->status === 'pending')
                    ->action(function ($record, array $data) {
                        $record->update([
                            'status' => 'rejected',
                            'rejection_reason' => $data['rejection_reason'],
                            'verified_by' => auth()->id(),
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
                        ->action(fn (Collection $records) => $records->each(fn ($record) => $record->update([
                            'status' => 'approved',
                            'verified_by' => auth()->id(),
                            'verified_at' => now(),
                        ])))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}























