<?php

namespace App\Filament\Resources\AdminNotificationCampaigns\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdminNotificationCampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title_en')
                    ->label(__('admin.ui.campaigns.english_title'))
                    ->searchable()
                    ->html()
                    ->limit(50),
                TextColumn::make('audience_mode')
                    ->label(__('admin.ui.campaigns.audience_mode'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('audience.name')
                    ->label(__('admin.ui.campaigns.audience_group'))
                    ->placeholder(__('admin.ui.campaigns.not_available'))
                    ->toggleable(),
                TextColumn::make('type')
                    ->label(__('admin.ui.campaigns.type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.ui.campaigns.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('total_recipients')
                    ->label(__('admin.ui.campaigns.total'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('delivered_count')
                    ->label(__('admin.ui.campaigns.delivered'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('failed_count')
                    ->label(__('admin.ui.campaigns.failed'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('sender.email')
                    ->label(__('admin.ui.campaigns.sent_by'))
                    ->toggleable(),
                TextColumn::make('sent_at')
                    ->label(__('admin.ui.campaigns.sent_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('audience_mode')
                    ->label(__('admin.ui.campaigns.audience_mode'))
                    ->options([
                        'specific' => __('admin.ui.campaigns.specific'),
                        'audience' => __('admin.ui.campaigns.audience'),
                        'all' => __('admin.ui.campaigns.all'),
                    ]),
                SelectFilter::make('status')
                    ->label(__('admin.ui.campaigns.status'))
                    ->options([
                        'draft' => __('admin.ui.campaigns.draft'),
                        'sending' => __('admin.ui.campaigns.sending'),
                        'sent' => __('admin.ui.campaigns.sent'),
                    ]),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('sent_at', 'desc');
    }
}
