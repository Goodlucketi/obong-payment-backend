<?php

declare(strict_types=1);

namespace Obong\Payment\Core;

final class Environment
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $values = parse_ini_file($path, false, INI_SCANNER_RAW);
        if (!is_array($values)) {
            throw new \RuntimeException('Unable to read backend environment file.');
        }

        foreach ($values as $name => $value) {
            if (getenv((string) $name) === false) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
    }
}