<?php

namespace Genba;

/**
 * The one place where the Kirby part of Genba writes to the PHP error log
 * (Kirby-free). Shared file — owned by the architect (SPEC.md §2).
 *
 * Every message is written once per process: a page renders the stamp and
 * the build info and reads the options several times, and a broken option
 * must not fill the log with copies. Tests call forget() to see a message
 * again.
 *
 * The command-line tools do NOT use this class: they write to STDOUT/STDERR
 * through the streams handed to their run() method (SPEC.md §3.0).
 */
final class Log
{
    /** @var array<string, true> */
    private static array $seen = [];

    /** Writes $message to the error log unless it was written before in this process. */
    public static function error(string $message): void
    {
        if (isset(self::$seen[$message])) {
            return;
        }
        self::$seen[$message] = true;
        error_log($message);
    }

    /** Forgets the messages already written (tests only). */
    public static function forget(): void
    {
        self::$seen = [];
    }
}
