<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use App\Models\DocumentList;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Document Requests';

    protected static string | \BackedEnum | null $icon = 'heroicon-o-document-text';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Verification')
                    ->columns(1)
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])
                            ->required(),
                        Textarea::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->maxLength(255)
                            ->visible(fn(callable $get) => $get('status') === 'rejected'),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Document Type')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn(?string $state): string => $this->formatDocumentType($state)),
                TextColumn::make('file_front')
                    ->label('Front File')
                    ->formatStateUsing(fn(?string $state): string => $state ? 'View' : 'N/A')
                    ->url(fn($record) => $record && $record->file_front ? asset('storage/' . $record->file_front) : null)
                    ->openUrlInNewTab()
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color(fn($record) => $record && $record->file_front ? 'primary' : 'gray')
                    ->tooltip('Open uploaded front file'),
                TextColumn::make('file_back')
                    ->label('Back File')
                    ->formatStateUsing(fn(?string $state): string => $state ? 'View' : 'N/A')
                    ->url(fn($record) => $record && $record->file_back ? asset('storage/' . $record->file_back) : null)
                    ->openUrlInNewTab()
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color(fn($record) => $record && $record->file_back ? 'primary' : 'gray')
                    ->tooltip('Open uploaded back file'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->colors([
                        'success' => 'approved',
                        'danger' => 'rejected',
                        'warning' => 'pending',
                    ]),
                TextColumn::make('rejection_reason')
                    ->label('Rejection Reason')
                    ->wrap()
                    ->toggleable()
                    ->visible(fn($record) => $record?->status === 'rejected'),
                TextColumn::make('verifiedBy.name')
                    ->label('Handled By')
                    ->default('Not handled')
                    ->toggleable(),
                TextColumn::make('verified_at')
                    ->label('Handled At')
                    ->dateTime()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Document Type')
                    ->options($this->getTypeOptions()),
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->headerActions([])
            ->actions([
                Action::make('approve')
                    ->icon('heroicon-o-check')
                    ->label('Approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn($record): bool => $record->status === 'pending')
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'approved',
                            'rejection_reason' => null,
                            'verified_by' => Auth::id(),
                            'verified_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Document approved')
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->icon('heroicon-o-x-mark')
                    ->label('Reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn($record): bool => $record->status === 'pending')
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update([
                            'status' => 'rejected',
                            'rejection_reason' => $data['rejection_reason'],
                            'verified_by' => Auth::id(),
                            'verified_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Document rejected')
                            ->danger()
                            ->send();
                    }),
                Action::make('markPending')
                    ->icon('heroicon-o-arrow-path')
                    ->label('Mark Pending')
                    ->color('gray')
                    ->visible(fn($record): bool => $record->status !== 'pending')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'pending',
                            'rejection_reason' => null,
                            'verified_by' => null,
                            'verified_at' => null,
                        ]);

                        Notification::make()
                            ->title('Document moved back to pending')
                            ->info()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkApprove')
                        ->label('Approve Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => 'approved',
                                    'rejection_reason' => null,
                                    'verified_by' => Auth::id(),
                                    'verified_at' => now(),
                                ]);
                            }

                            Notification::make()
                                ->title('Selected documents approved')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('bulkReject')
                        ->label('Reject Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->schema([
                            Textarea::make('rejection_reason')
                                ->label('Rejection Reason')
                                ->required()
                                ->maxLength(255),
                        ])
                        ->action(function ($records, array $data) {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => 'rejected',
                                    'rejection_reason' => $data['rejection_reason'],
                                    'verified_by' => Auth::id(),
                                    'verified_at' => now(),
                                ]);
                            }

                            Notification::make()
                                ->title('Selected documents rejected')
                                ->danger()
                                ->send();
                        }),
                ]),
            ]);
    }

    protected function getTypeOptions(): array
    {
        return DocumentList::query()
            ->active()
            ->ordered()
            ->pluck('name', 'name')
            ->toArray();
    }

    protected function formatDocumentType(?string $type): string
    {
        if (!$type) {
            return 'Unknown';
        }

        $lookup = $this->getTypeLookup();
        $normalized = Str::of($type)->snake()->toString();
        $slug = Str::of($type)->slug()->toString();

        return $lookup[$type]
            ?? $lookup[$normalized]
            ?? $lookup[$slug]
            ?? Str::of($type)->headline();
    }

    protected function getTypeLookup(): array
    {
        static $lookup;

        if ($lookup !== null) {
            return $lookup;
        }

        $lookup = [];

        DocumentList::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->each(function (DocumentList $documentList) use (&$lookup) {
                $label = $documentList->name;
                $snake = Str::of($label)->snake()->toString();
                $slug = Str::of($label)->slug()->toString();

                $lookup[$label] = $label;
                $lookup[$snake] = $label;
                $lookup[$slug] = $label;
            });

        return $lookup;
    }
}
