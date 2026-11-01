<?php

namespace App\Filament\Resources\SubscriptionAuditLogs\Pages;

use App\Filament\Resources\SubscriptionAuditLogs\SubscriptionAuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionAuditLogs extends ListRecords
{
    protected static string $resource = SubscriptionAuditLogResource::class;
}
