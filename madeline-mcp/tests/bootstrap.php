<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'MadelineMcp\\';
    if (\str_starts_with($class, $prefix)) {
        $rel = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
        $file = __DIR__.'/../src/'.$rel.'.php';
        if (\is_file($file)) {
            require $file;
        }
    }
});