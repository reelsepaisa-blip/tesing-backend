<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

class LatestBookings extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected function paginateTableQuery(Builder $query): Paginator
    {
        return $query->paginate(
            perPage: ($this->getTableRecordsPerPage() === 'all') ? $query->count() : $this->getTableRecordsPerPage(),
            pageName: $this->getTablePaginationPageName(),
        )->onEachSide(0);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Booking::query()
                    ->latest()
            )
            ->columns([
                Tables\Columns\TextColumn::make('booking_code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('driver.name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('pickup_address')
                    ->limit(30),
                Tables\Columns\TextColumn::make('dropoff_address')
                    ->limit(30),
                Tables\Columns\TextColumn::make('total_amount')
                    ->money('INR'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'gray',
                        'searching' => 'warning',
                        'accepted' => 'info',
                        'arrived' => 'warning',
                        'auto_arriving' => 'warning',
                        'arriving' => 'warning',
                        'started' => 'warning',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        'expired' => 'danger',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime(),
            ])
            ->defaultPaginationPageOption(2)
            ->paginationPageOptions([10, 25, 50])
            ->extremePaginationLinks();
    }
}
