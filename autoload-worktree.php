<?php declare(strict_types=1);

$loader = require __DIR__ . '/vendor/autoload.php';
if ($loader instanceof \Composer\Autoload\ClassLoader) {
    // Prepend worktree src so local Migrations and BackupStore are picked up first.
    $loader->addPsr4('danog\\MadelineProto\\', __DIR__ . '/src', true);
}
