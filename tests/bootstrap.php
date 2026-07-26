<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * The suite deliberately runs without booting Craft: the plugin's logic lives
 * in plain PHP classes behind interfaces (HTTP transport, token provider,
 * clock), so everything meaningful can be exercised in milliseconds and
 * without a database. Classes that do touch Craft are covered through those
 * seams instead.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Yii2 is not autoloadable on its own — `Yii.php` is what registers the class
// map, the DI container and the `Yii` alias. Craft normally does this during
// its own bootstrap; the Feature suite needs it so Yii's validators (used by
// the settings model) can be instantiated without a full application.
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');
