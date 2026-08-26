<?php declare(strict_types=1);

// Worktree-local autoloader: prepend this worktree's src/ so new Backup classes
// (and the full fork tree in this worktree) resolve here instead of the main repo.
$loader = require __DIR__ . '/vendor/autoload.php';
if (method_exists($loader, 'addPsr4')) {
    $loader->addPsr4('danog\\MadelineProto\\', __DIR__ . '/src');
}
return $loader;
