<?php

namespace App\Filament\Resources\PromoResource\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UsagesRelationManager extends RelationManager
{
    protected static string $relationship = 'usages';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->with(['user', 'booking', 'promoCode']))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('booking.booking_code')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('discount_amount')
                    ->money('INR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn($state): string => match ($state) {
                        'applied' => 'Applied',
                        'cancelled' => 'Cancelled',
                        'expired' => 'Expired',
                        default => ucfirst($state ?? 'Applied'),
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'applied' => 'success',
                        'cancelled' => 'danger',
                        'expired' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                    ->searchable()
                    ->preload()
                    ->multiple(),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Usage Status')
                    ->options([
                        'applied' => 'Applied',
                        'cancelled' => 'Cancelled',
                        'expired' => 'Expired',
                    ])
                    ->query(function (Builder $query, $state): Builder {
                        if (empty($state)) {
                            return $query;
                        }

                        $statuses = is_array($state) ? $state : [$state];
                        $bookingStatuses = [];

                        foreach ($statuses as $status) {

                            $bookingStatuses = array_merge($bookingStatuses, match ($status) {
                                'applied' => ['completed'], // Applied means completed booking
                                'cancelled' => ['cancelled'],
                                'expired' => ['expired'],
                                default => [],
                            });
                        }

                        if (!empty($bookingStatuses)) {
                            return $query->whereHas('booking', function (Builder $query) use ($bookingStatuses) {
                                $query->whereIn('status', array_unique($bookingStatuses));
                            });
                        }

                        return $query;
                    })
                    ->multiple(),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->headerActions([])
            ->actions([
                 ViewAction::make()
                    ->modalHeading('View promo usage')
                    ->modalContent(function ($record) {

                        $record->load(['user', 'booking', 'promoCode']);

                        return view('filament.resources.promo-resource.view-usage-modal', [
                            'record' => $record,
                        ]);
                    }),
            ])
            ->bulkActions([]);
    }
}
