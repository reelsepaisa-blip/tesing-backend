<?php

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Actions\Action;
use App\Models\SystemConfiguration;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class RefundSettings extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Refund Settings';

    protected static string | \UnitEnum | null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.refund-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'refund_required_hours' => SystemConfiguration::getValue('refund_required_hours', 48),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Refund Processing Window')
                    ->description('Configure how long (in hours) the support team can take to complete refund reviews.')
                    ->schema([
                        TextInput::make('refund_required_hours')
                            ->label('Refund Required Hours')
                            ->helperText('This value is shared with the apps to communicate refund timelines to users.')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(240)
                            ->default(48)
                            ->required()
                            ->suffix('hrs')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        // Check demo mode
        if (config('app.demo_mode', false)) {
            Notification::make()
                ->title('Demo Mode Enabled')
                ->body('Demo mode is enabled. Changes to refund settings are disabled to protect demo data.')
                ->danger()
                ->send();
            return;
        }

        $data = $this->form->getState();
        $hours = (int) ($data['refund_required_hours'] ?? 0);

        if ($hours < 1 || $hours > 240) {
            Notification::make()
                ->title('Invalid value')
                ->body('Refund required hours must be between 1 and 240 hours.')
                ->danger()
                ->send();

            return;
        }

        SystemConfiguration::updateOrCreate(
            ['key' => 'refund_required_hours'],
            [
                'value' => (string) $hours,
                'category' => 'support',
                'description' => 'Number of hours the support team requires to process refund requests',
                'is_active' => true,
                'is_encrypted' => false,
            ]
        );

        Notification::make()
            ->title('Refund settings saved')
            ->body("Refund required hours updated to {$hours} hours.")
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Reset to Default')
                ->color('warning')
                ->action(function () {
                    $this->form->fill([
                        'refund_required_hours' => 48,
                    ]);

                    Notification::make()
                        ->title('Settings reset to default values (48 hours)')
                        ->warning()
                        ->send();
                })
                ->requiresConfirmation(),
            Action::make('save')
                ->label('Save Settings')
                ->color('success')
                ->requiresConfirmation(false)
                ->action(fn() => $this->save())
                ->keyBindings(['mod+s']),
        ];
    }

    public function getHeading(): string
    {
        return 'Refund Settings';
    }

    public function getSubheading(): string
    {
        return 'Manage refund processing timelines shared with users and drivers.';
    }
}
