<?php

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Illuminate\Validation\ValidationException;
use Exception;
use App\Models\SystemConfiguration;
use App\Services\SystemUpdateService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;

class SystemUpdate extends Page
{
    use WithFileUploads;
    
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'System Update';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 201;

    protected string $view = 'filament.pages.system-update';

    protected static ?string $slug = 'system-update';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'purchase_code' => SystemConfiguration::getValue('purchase_code', ''),
            'update_file' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('purchase_code')
                            ->label('Purchase Code')
                            ->placeholder('Enter your purchase code')
                            ->maxLength(255)
                            ->required()
                            ->columnSpan(1),

                        FileUpload::make('update_file')
                            ->label('Update File')
                            ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'zip'])
                            ->disk('local')
                            ->directory('updates')
                            ->visibility('private')
                            ->maxSize(102400) // 100MB in KB
                            ->helperText('Upload ZIP file (max 100MB)')
                            ->downloadable()
                            ->previewable(false)
                            ->openable()
                            ->required()
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function getSystemVersion(): string
    {
        return SystemConfiguration::getValue('system_version', '2.10.0');
    }

    public function save(): void
    {
        // Check demo mode
        if (config('app.demo_mode', false)) {
            Notification::make()
                ->title('Demo Mode Enabled')
                ->body('Demo mode is enabled. System updates are disabled to protect demo data.')
                ->danger()
                ->send();
            return;
        }

        try {
            // Validate form first
            $this->form->validate();
            
            // Get form state
            $data = $this->form->getState();
        } catch (ValidationException $e) {
            // Handle validation errors
            Notification::make()
                ->title('Validation Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
            return;
        } catch (Exception $e) {
            // Handle other errors (like file size retrieval)
            
            Notification::make()
                ->title('Upload Error')
                ->body('There was an error processing the file upload. Please try again or check file permissions.')
                ->danger()
                ->send();
            return;
        }

        $purchaseCode = $data['purchase_code'] ?? '';
        $updateFile = $data['update_file'] ?? null;

        // Validate purchase code
        if (empty($purchaseCode)) {
            Notification::make()
                ->title('Validation Error')
                ->body('Purchase code is required.')
                ->danger()
                ->send();
            return;
        }

        // Validate purchase code with wrteam validator
        $updateService = new SystemUpdateService();
        $validationResult = $updateService->validatePurchaseCode($purchaseCode);
        
        if (!$validationResult['success']) {
            Notification::make()
                ->title('Purchase Code Validation Failed')
                ->body($validationResult['message'])
                ->danger()
                ->send();
            return;
        }

        // Validate update file
        if (empty($updateFile)) {
            Notification::make()
                ->title('Validation Error')
                ->body('Update file is required.')
                ->danger()
                ->send();
            return;
        }

        try {
            // Save purchase code
            SystemConfiguration::updateOrCreate(
                ['key' => 'purchase_code'],
                [
                    'value' => $purchaseCode,
                    'category' => 'system',
                    'description' => 'CodeCanyon purchase code for system updates',
                    'is_active' => true,
                    'is_encrypted' => false,
                ]
            );

            // Handle file upload
            if ($updateFile) {
                try {
                    // Check if file exists and is valid
                    if (is_string($updateFile)) {
                        // File path string (already stored)
                        $filePath = $updateFile;
                    } else {
                        // Livewire uploaded file - store it
                        $filePath = $updateFile->store('updates', 'local');
                    }
                    
                    // Save file path to configuration
                    SystemConfiguration::updateOrCreate(
                        ['key' => 'last_update_file'],
                        [
                            'value' => $filePath,
                            'category' => 'system',
                            'description' => 'Last uploaded update file path',
                            'is_active' => true,
                            'is_encrypted' => false,
                        ]
                    );
                } catch (Exception $e) {
                    
                    Notification::make()
                        ->title('File Upload Error')
                        ->body('Failed to store the update file: ' . $e->getMessage())
                        ->danger()
                        ->send();
                    return;
                }

                // Process the update (purchase code already validated above)
                $result = $updateService->processUpdate($filePath, $purchaseCode);

                if (!$result['success']) {
                    Notification::make()
                        ->title('Update Processing Failed')
                        ->body($result['message'])
                        ->danger()
                        ->send();
                    return;
                }

                $versionMessage = isset($result['version']) ? " System updated to version {$result['version']}." : '';
                
                Notification::make()
                    ->title('Update Processed Successfully')
                    ->body('Purchase code saved. ' . ($result['message'] ?? 'Update file processed successfully.') . $versionMessage)
                    ->success()
                    ->send();
            } else {
                // No file uploaded, just save purchase code
                Notification::make()
                    ->title('Purchase Code Saved')
                    ->body('Purchase code saved successfully.')
                    ->success()
                    ->send();
            }

        } catch (Exception $e) {
            Notification::make()
                ->title('Update Failed')
                ->body('An error occurred while processing the update: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHeading(): string
    {
        return 'System Update';
    }

    public function getSubheading(): string
    {
        return 'Update your system with the latest version from CodeCanyon';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }
}
