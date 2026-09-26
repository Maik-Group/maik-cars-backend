<?php

namespace App\Filament\Resources\ItemImageSubmissions\Tables;

use App\Models\ItemImageSubmission;
use App\Services\ItemImageSubmissionReviewService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;

class ItemImageSubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('submitted_image')
                    ->label(__('admin.ui.image_submissions.image'))
                    ->getStateUsing(fn (ItemImageSubmission $record): string => self::imageUrl($record))
                    ->size(96),
                TextColumn::make('item.serial_code')
                    ->label(__('admin.ui.image_submissions.serial'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('item.model')
                    ->label(__('admin.ui.image_submissions.product'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('item.carGroup.name')
                    ->label(__('admin.ui.image_submissions.car_group'))
                    ->sortable(),
                TextColumn::make('submitter.email')
                    ->label(__('admin.ui.image_submissions.submitted_by'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.ui.image_submissions.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('admin.ui.image_submissions.submitted_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('reviewer.email')
                    ->label(__('admin.ui.image_submissions.reviewed_by'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reviewed_at')
                    ->label(__('admin.ui.image_submissions.reviewed_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('rejection_reason')
                    ->label(__('admin.ui.image_submissions.rejection_reason'))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.ui.image_submissions.status'))
                    ->default(ItemImageSubmission::STATUS_PENDING)
                    ->options([
                        ItemImageSubmission::STATUS_PENDING => __('admin.ui.image_submissions.pending'),
                        ItemImageSubmission::STATUS_APPROVED => __('admin.ui.image_submissions.approved'),
                        ItemImageSubmission::STATUS_REJECTED => __('admin.ui.image_submissions.rejected'),
                    ]),
            ])
            ->recordActions([
                Action::make('viewImage')
                    ->label(__('admin.ui.image_submissions.view_image'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (ItemImageSubmission $record): string => self::imageUrl($record))
                    ->openUrlInNewTab(),
                Action::make('approve')
                    ->label(__('admin.ui.image_submissions.approve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.ui.image_submissions.approve_heading'))
                    ->modalDescription(__('admin.ui.image_submissions.approve_description'))
                    ->visible(fn (ItemImageSubmission $record): bool => $record->status === ItemImageSubmission::STATUS_PENDING)
                    ->action(function (ItemImageSubmission $record): void {
                        try {
                            app(ItemImageSubmissionReviewService::class)->approve(
                                $record,
                                (int) auth()->id(),
                            );
                        } catch (RuntimeException $exception) {
                            Notification::make()
                                ->title(__('admin.ui.image_submissions.review_failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.ui.image_submissions.approved_title'))
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->label(__('admin.ui.image_submissions.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading(__('admin.ui.image_submissions.reject_heading'))
                    ->visible(fn (ItemImageSubmission $record): bool => $record->status === ItemImageSubmission::STATUS_PENDING)
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('admin.ui.image_submissions.rejection_reason'))
                            ->rows(3),
                    ])
                    ->action(function (array $data, ItemImageSubmission $record): void {
                        try {
                            app(ItemImageSubmissionReviewService::class)->reject(
                                $record,
                                (int) auth()->id(),
                                $data['reason'] ?? null,
                            );
                        } catch (RuntimeException $exception) {
                            Notification::make()
                                ->title(__('admin.ui.image_submissions.review_failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.ui.image_submissions.rejected_title'))
                            ->warning()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function imageUrl(ItemImageSubmission $record): string
    {
        return $record->getFirstMediaUrl('submitted_image', 'thumb')
            ?: $record->getFirstMediaUrl('submitted_image');
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            ItemImageSubmission::STATUS_PENDING => __('admin.ui.image_submissions.pending'),
            ItemImageSubmission::STATUS_APPROVED => __('admin.ui.image_submissions.approved'),
            ItemImageSubmission::STATUS_REJECTED => __('admin.ui.image_submissions.rejected'),
            default => $status,
        };
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            ItemImageSubmission::STATUS_PENDING => 'warning',
            ItemImageSubmission::STATUS_APPROVED => 'success',
            ItemImageSubmission::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }
}
