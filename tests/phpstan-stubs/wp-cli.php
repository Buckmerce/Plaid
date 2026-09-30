<?php

// Minimal WP-CLI stub for static analysis (php-stubs/wp-cli-stubs does not support WordPress stubs 7.x).

class WP_CLI
{
    /** @param class-string|callable $callable */
    public static function add_command(string $name, $callable, array $args = array()): bool
    {
        return true;
    }

    public static function line(string $message = ''): void
    {
    }

    public static function log(string $message): void
    {
    }

    public static function success(string $message): void
    {
    }

    public static function warning(string $message): void
    {
    }

    /** @param array<string, mixed> $assoc_args */
    public static function confirm(string $question, array $assoc_args = array()): void
    {
    }

    /** @return never */
    public static function error(string $message, bool $exit = true)
    {
        exit(1);
    }
}
