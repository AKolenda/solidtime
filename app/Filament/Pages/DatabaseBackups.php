<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\DatabaseBackupSetting;
use DateTimeZone;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DatabaseBackups extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Database backups';

    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.database-backups';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = DatabaseBackupSetting::query()->first();

        $this->form->fill([
            'enabled' => $settings?->enabled ?? (bool) config('database-backup.enabled'),
            'time' => $settings?->time ?? (string) config('database-backup.time'),
            'timezone' => $settings?->timezone ?? (string) config('database-backup.timezone'),
            'retention_days' => $settings?->retention_days ?? (int) config('database-backup.retention_days'),
            'subdirectory' => $settings?->subdirectory ?? '',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Toggle::make('enabled')
                    ->label('Enable daily backups')
                    ->helperText('The scheduler container must have the backup mount before this can run.'),
                Placeholder::make('root_path')
                    ->label('Mounted backup root')
                    ->content((string) config('database-backup.root_path'))
                    ->helperText('Docker controls this one permanent mount. Solidtime cannot change it from inside the container.'),
                TextInput::make('subdirectory')
                    ->label('Subfolder (optional)')
                    ->placeholder('Leave empty to write directly to the mounted root')
                    ->helperText('Letters, numbers, dots, underscores, and hyphens only.')
                    ->maxLength(255)
                    ->regex('/^[A-Za-z0-9][A-Za-z0-9._-]*$/')
                    ->nullable(),
                TimePicker::make('time')
                    ->label('Daily backup time')
                    ->seconds(false)
                    ->required(),
                Select::make('timezone')
                    ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                    ->searchable()
                    ->required(),
                TextInput::make('retention_days')
                    ->label('Keep backups for')
                    ->suffix('days')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(3650)
                    ->required(),
            ])
            ->columns(2)
            ->statePath('data');
    }

    public function save(): void
    {
        /** @var array{enabled: bool, time: string, timezone: string, retention_days: int, subdirectory: string|null} $data */
        $data = $this->form->getState();
        $data['subdirectory'] = $data['subdirectory'] ?? '';

        $settings = DatabaseBackupSetting::query()->firstOrNew();
        $settings->fill($data);
        $settings->save();

        Notification::make()
            ->title('Database backup settings saved')
            ->success()
            ->send();
    }
}
