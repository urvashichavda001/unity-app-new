<?php

namespace App\Services;

use App\Models\AppInstance;

class AppConfigService
{
    public const GREENPRENEUR_SLUG = 'greenpreneur';

    public function getGreenpreneurAppInstance(): AppInstance
    {
        return AppInstance::query()->updateOrCreate(
            ['slug' => self::GREENPRENEUR_SLUG],
            [
                'name' => 'Greenpreneur',
                'display_name' => 'Greenpreneur',
                'is_active' => true,
            ]
        );
    }
}
