<?php

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Exception;
use Filament\Actions\Action;
use App\Models\SystemConfiguration;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DriverSettings extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Driver Settings';
    protected static string | \UnitEnum | null $navigationGroup = 'Driver Management';
    protected static ?int $navigationSort = 100;
    protected static bool $shouldRegisterNavigation = false;
    protected string $view = 'filament.pages.driver-settings';



    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'document_upload_deadline_hours' => SystemConfiguration::getValue('document_upload_deadline_hours', 24),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Document Upload Settings')
                    ->description('Configure document upload requirements and deadlines for drivers')
                    ->schema([
                        TextInput::make('document_upload_deadline_hours')
                            ->label('Document Upload Deadline (Hours)')
                            ->helperText('Number of hours drivers have to upload new documents after they are added. If drivers do not upload within this time, their account will be blocked.')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(168) // Max 7 days (168 hours)
                            ->default(24)
                            ->required()
                            ->suffix('hours')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        // Check if user is restricted (ID 2)
        $userId = auth()->id();
        if ($userId === 2) {
            Notification::make()
                ->title('Access Restricted')
                ->body('You do not have permission to update driver settings.')
                ->danger()
                ->send();
            return;
        }

        try {
            $data = $this->form->getState();

            if (!isset($data['document_upload_deadline_hours']) || empty($data['document_upload_deadline_hours'])) {
                Notification::make()
                    ->title('Validation Error')
                    ->body('Document upload deadline is required.')
                    ->danger()
                    ->send();
                return;
            }

            $hours = (int) $data['document_upload_deadline_hours'];

            if ($hours < 1 || $hours > 168) {
                Notification::make()
                    ->title('Invalid value')
                    ->body('Document upload deadline must be between 1 and 168 hours (7 days).')
                    ->danger()
                    ->send();
                return;
            }

            $config = SystemConfiguration::updateOrCreate(
                ['key' => 'document_upload_deadline_hours'],
                [
                    'value' => (string) $hours,
                    'category' => 'driver',
                    'description' => 'Number of hours drivers have to upload new documents after they are added to the document list',
                    'is_active' => true,
                    'is_encrypted' => false,
                ]
            );

            Notification::make()
                ->title('Driver settings saved successfully')
                ->body("Document upload deadline set to {$hours} hours.")
                ->success()
                ->send();
        } catch (Exception $e) {
            Notification::make()
                ->title('Error saving settings')
                ->body('An error occurred while saving. Please try again.')
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Reset to Default')
                ->color('warning')
                ->action(function () {
                    $this->form->fill([
                        'document_upload_deadline_hours' => 24,
                    ]);
                    Notification::make()
                        ->title('Settings reset to default values (24 hours)')
                        ->warning()
                        ->send();
                })
                ->requiresConfirmation(),

            Action::make('save')
                ->label('Save Settings')
                ->color('success')
                ->requiresConfirmation(false)
                ->action(function () {
                    $this->save();
                })
                ->keyBindings(['mod+s']),
        ];
    }

    public function getHeading(): string
    {
        return 'Driver Settings';
    }

    public function getSubheading(): string
    {
        return 'Configure driver-related settings including document upload deadlines';
    }
}
