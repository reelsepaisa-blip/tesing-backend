<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

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
                Forms\Components\Select::make('document_type')
                    ->options([
                        'license' => 'Driver License',
                        'identity' => 'Identity Card',
                        'address_proof' => 'Address Proof',
                        'insurance' => 'Insurance',
                        'other' => 'Other',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('document_number')
                    ->maxLength(255),
                Forms\Components\FileUpload::make('document_url')
                    ->required()
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->rules(['mimes:jpg,jpeg,png,webp'])
                    ->maxSize(1024), // 1MB
                Forms\Components\DatePicker::make('expiry_date'),
                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'expired' => 'Expired',
                    ])
                    ->required(),
                Forms\Components\Textarea::make('rejection_reason')
                    ->maxLength(65535)
                    ->visible(fn($get) => $get('status') === 'rejected'),
                Forms\Components\KeyValue::make('meta_data')
                    ->keyLabel('Key')
                    ->valueLabel('Value'),
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
                Tables\Columns\ImageColumn::make('document_url')
                    ->circular(),
                Tables\Columns\TextColumn::make('expiry_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\SelectColumn::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'expired' => 'Expired',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('verified_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('verifiedBy.name')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'expired' => 'Expired',
                    ]),
                Tables\Filters\SelectFilter::make('document_type')
                    ->options([
                        'license' => 'Driver License',
                        'identity' => 'Identity Card',
                        'address_proof' => 'Address Proof',
                        'insurance' => 'Insurance',
                        'other' => 'Other',
                    ]),
            ])
            ->headerActions([
                 CreateAction::make(),
            ])
            ->actions([
                 EditAction::make(),
                 DeleteAction::make(),
                 Action::make('approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn($record) => $record->status === 'pending')
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'approved',
                            'verified_at' => now(),
                            'verified_by' => auth()->id(),
                        ]);
                    }),
                 Action::make('reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->required()
                            ->maxLength(65535),
                    ])
                    ->visible(fn($record) => $record->status === 'pending')
                    ->action(function ($record, array $data) {
                        $record->update([
                            'status' => 'rejected',
                            'rejection_reason' => $data['rejection_reason'],
                            'verified_at' => now(),
                            'verified_by' => auth()->id(),
                        ]);
                    }),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     DeleteBulkAction::make(),
                ]),
            ]);
    }
}
