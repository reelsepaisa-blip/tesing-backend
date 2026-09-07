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

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Exception;
use App\Filament\Resources\FailedJobResource\Pages\ListFailedJobs;
use App\Filament\Resources\FailedJobResource\Pages;
use App\Models\FailedJob;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;


class FailedJobResource extends Resource
{
    protected static ?string $model = FailedJob::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static ?string $navigationLabel = 'Failed Jobs';

    protected static ?string $pluralModelLabel = 'Failed Jobs';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('queue')
                    ->label('Queue')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('connection')
                    ->label('Connection')
                    ->sortable(),

                TextColumn::make('job_class')
                    ->label('Job Class')
                    ->limit(50)
                    ->tooltip(function ($record) {
                        return $record->job_class;
                    }),

                TextColumn::make('short_exception')
                    ->label('Error Message')
                    ->limit(100)
                    ->tooltip(function ($record) {
                        return $record->exception;
                    }),

                TextColumn::make('failed_at')
                    ->label('Failed At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('queue')
                    ->options(function () {
                        return DB::table('failed_jobs')
                            ->distinct()
                            ->pluck('queue', 'queue')
                            ->toArray();
                    }),

                Filter::make('failed_at')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Failed From'),
                        DatePicker::make('until')
                            ->label('Failed Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn($query, $date): Builder => $query->whereDate('failed_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn($query, $date): Builder => $query->whereDate('failed_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                ViewAction::make()
                    ->modalHeading('Failed Job Details')
                    ->modalContent(function ($record) {
                        $payload = json_decode($record->payload, true);
                        return view('filament.resources.failed-job.view-modal', [
                            'record' => $record,
                            'payload' => $payload,
                        ]);
                    }),

                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Retry Failed Job')
                    ->modalDescription('This will attempt to retry the failed job.')
                    ->action(function ($record) {
                        try {

                            Artisan::call('queue:retry', ['id' => $record->uuid ?? $record->id]);


                            Notification::make()
                                ->title('Job Retried')
                                ->body('The failed job has been queued for retry.')
                                ->success()
                                ->send();
                        } catch (Exception $e) {

                            Notification::make()
                                ->title('Retry Failed')
                                ->body('Failed to retry the job: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete Failed Job')
                    ->modalDescription('This will permanently remove the failed job record.')
                    ->action(function ($record) {
                        try {
                            DB::table('failed_jobs')->where('id', $record->id)->delete();


                            Notification::make()
                                ->title('Job Deleted')
                                ->body('The failed job record has been deleted.')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Delete Failed')
                                ->body('Failed to delete the job: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->headerActions([
                Action::make('retry_all')
                    ->label('Retry All')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Retry All Failed Jobs')
                    ->modalDescription('This will attempt to retry all failed jobs.')
                    ->action(function () {
                        try {
                            Artisan::call('queue:retry', ['id' => 'all']);


                            Notification::make()
                                ->title('All Jobs Retried')
                                ->body('All failed jobs have been queued for retry.')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Retry All Failed')
                                ->body('Failed to retry all jobs: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('clear_all')
                    ->label('Clear All')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Clear All Failed Jobs')
                    ->modalDescription('This will permanently remove all failed job records.')
                    ->action(function () {
                        try {
                            $count = DB::table('failed_jobs')->count();
                            DB::table('failed_jobs')->delete();


                            Notification::make()
                                ->title('All Jobs Cleared')
                                ->body("All {$count} failed job records have been cleared.")
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Clear All Failed')
                                ->body('Failed to clear all jobs: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkAction::make('retry_selected')
                    ->label('Retry Selected')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        try {
                            foreach ($records as $record) {
                                Artisan::call('queue:retry', ['id' => $record->uuid ?? $record->id]);
                            }


                            Notification::make()
                                ->title('Selected Jobs Retried')
                                ->body(count($records) . ' failed jobs have been queued for retry.')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Retry Failed')
                                ->body('Failed to retry selected jobs: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                BulkAction::make('delete_selected')
                    ->label('Delete Selected')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        try {
                            $ids = collect($records)->pluck('id');
                            DB::table('failed_jobs')->whereIn('id', $ids)->delete();


                            Notification::make()
                                ->title('Selected Jobs Deleted')
                                ->body(count($records) . ' failed job records have been deleted.')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Delete Failed')
                                ->body('Failed to delete selected jobs: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('failed_at', 'desc')
            ->poll('30s'); // Auto-refresh every 30 seconds
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFailedJobs::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = FailedJob::count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $count = FailedJob::count();
        return $count > 0 ? 'danger' : null;
    }
}
