<?php

declare(strict_types=1);

namespace App\Exceptions\Api;

class TimeEntryIsNotRunningApiException extends ApiException
{
    public const string KEY = 'time_entry_is_not_running';
}
