<?php

namespace App\Filament\Pages;

use App\Enums\NotificationType;
use App\Models\NotificationAudience;
use App\Models\User;
use App\Services\AdminNotificationCampaignService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

class SendNotifications extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static ?string $title = 'Send Notifications';

    protected static ?string $navigationLabel = 'Send Notifications';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|\UnitEnum|null $navigationGroup = 'Communication';

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.send_notifications');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.communication');
    }

    public function getTitle(): string
    {
        return __('admin.nav.send_notifications');
    }

    protected static string $routePath = 'send-notifications';

    protected string $view = 'filament.pages.send-notifications';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'audience_mode' => 'specific',
            'type' => NotificationType::GENERALE_NOTIFICATION,
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('send_notifications') ?? false;
    }

    public function getSubheading(): ?string
    {
        return __('admin.ui.subheadings.send_notifications');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.ui.notifications.audience'))
                    ->description(__('admin.ui.notifications.audience_description'))
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->components([
                        Select::make('audience_mode')
                            ->label(__('admin.ui.notifications.audience_mode'))
                            ->options([
                                'specific' => __('admin.ui.notifications.specific_users'),
                                'audience' => __('admin.ui.notifications.audience_group'),
                                'all' => __('admin.ui.notifications.all_active_users'),
                            ])
                            ->default('specific')
                            ->required()
                            ->live(),
                        Select::make('audience_id')
                            ->label(__('admin.ui.notifications.audience_group'))
                            ->options(fn (): array => NotificationAudience::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->visible(fn (Get $get): bool => $get('audience_mode') === 'audience')
                            ->required(fn (Get $get): bool => $get('audience_mode') === 'audience')
                            ->searchable(),
                        Select::make('user_ids')
                            ->label(__('admin.ui.notifications.specific_users'))
                            ->multiple()
                            ->options(fn (): array => User::query()
                                ->appUsers()
                                ->where('is_active', true)
                                ->orderBy('email')
                                ->pluck('email', 'id')
                                ->all())
                            ->visible(fn (Get $get): bool => $get('audience_mode') === 'specific')
                            ->required(fn (Get $get): bool => $get('audience_mode') === 'specific')
                            ->searchable()
                            ->columnSpanFull(),
                        Hidden::make('type')
                            ->default(NotificationType::GENERALE_NOTIFICATION),
                    ]),
                Section::make(__('admin.ui.notifications.localized_content'))
                    ->description(__('admin.ui.notifications.localized_description'))
                    ->columns([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->components([
                        RichEditor::make('title_en')
                            ->label(__('admin.ui.notifications.title_en'))
                            ->required()
                            ->maxLength(150)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('title_ar')
                            ->label(__('admin.ui.notifications.title_ar'))
                            ->maxLength(150)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('body_en')
                            ->label(__('admin.ui.notifications.body_en'))
                            ->required()
                            ->maxLength(500)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('body_ar')
                            ->label(__('admin.ui.notifications.body_ar'))
                            ->maxLength(500)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('title_bg')
                            ->label(__('admin.ui.notifications.title_bg'))
                            ->maxLength(150)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('body_bg')
                            ->label(__('admin.ui.notifications.body_bg'))
                            ->maxLength(500)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('title_de')
                            ->label(__('admin.ui.notifications.title_de'))
                            ->maxLength(150)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('body_de')
                            ->label(__('admin.ui.notifications.body_de'))
                            ->maxLength(500)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('title_hr')
                            ->label(__('admin.ui.notifications.title_hr'))
                            ->maxLength(150)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('body_hr')
                            ->label(__('admin.ui.notifications.body_hr'))
                            ->maxLength(500)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('title_hu')
                            ->label(__('admin.ui.notifications.title_hu'))
                            ->maxLength(150)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('body_hu')
                            ->label(__('admin.ui.notifications.body_hu'))
                            ->maxLength(500)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                    ]),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        /** @var User|null $sender */
        $sender = auth()->user();

        if (! $sender) {
            Notification::make()
                ->title(__('admin.ui.notifications.unauthorized'))
                ->danger()
                ->send();

            return;
        }

        $data = $this->form->getState();
        $data['type'] = NotificationType::GENERALE_NOTIFICATION;

        try {
            app(AdminNotificationCampaignService::class)->sendCampaign($sender, $data);

            Notification::make()
                ->title(__('admin.ui.notifications.sent'))
                ->body(__('admin.ui.notifications.sent_body'))
                ->success()
                ->send();

            $this->form->fill([
                'audience_mode' => 'specific',
                'type' => NotificationType::GENERALE_NOTIFICATION,
            ]);
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('admin.ui.notifications.unable'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return array<int, string|array<int, string>>
     */
    private function editorToolbarButtons(): array
    {
        return [
            ['bold', 'italic', 'underline', 'strike'],
            ['h2', 'h3'],
            ['bulletList', 'orderedList', 'blockquote'],
            ['undo', 'redo'],
        ];
    }
}
