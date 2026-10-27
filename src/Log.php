<?php

namespace Sayamaapps\Genba;

/**
 * The one place where the Kirby part of Genba writes to the PHP error log
 * (Kirby-free).
 *
 * Every message is written once per process: a page renders the stamp and
 * the build info and reads the options several times, and a broken option
 * must not fill the log with copies.
 *
 * The command-line tools do NOT use this class: they write to STDOUT/STDERR
 * through the streams handed to their run() method (SPEC.md §3.0).
 *
 * @package   Sayamaapps Genba
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-genba
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Log
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
}
