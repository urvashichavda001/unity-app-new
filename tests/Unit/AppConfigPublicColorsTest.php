<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\AppConfigController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AppConfigPublicColorsTest extends TestCase
{
    public function test_public_default_colors_only_include_simplified_roles(): void
    {
        $method = new ReflectionMethod(AppConfigController::class, 'defaultColors');
        $method->setAccessible(true);

        $colors = $method->invoke(null);

        $this->assertSame([
            'primary_color',
            'primary_dark_color',
            'primary_ultra_light_color',
            'secondary_color',
            'text_primary_color',
            'text_secondary_color',
            'background_color',
            'card_background_color',
        ], array_keys($colors));

        $this->assertArrayNotHasKey('primary_light_color', $colors);
        $this->assertArrayNotHasKey('secondary_light_color', $colors);
        $this->assertArrayNotHasKey('background_light_color', $colors);
        $this->assertArrayNotHasKey('background_secondary_color', $colors);
        $this->assertArrayNotHasKey('background_dark_color', $colors);
        $this->assertArrayNotHasKey('card_border_color', $colors);
    }
}
