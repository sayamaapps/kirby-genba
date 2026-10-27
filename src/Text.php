<?php

namespace Sayamaapps\Genba;

/**
 * Text that was not written by the operator, made safe for one log line.
 * Kirby-free, shared by the tools (Links::clean() delegates to it, and the
 * pre-deploy check prints its findings through it).
 *
 * What reaches a log line and is not the operator's own setting comes from
 * the site: a line of a template (the finding), a file or directory name, a
 * fetched title, a redirect target. Any of them can carry an ESC sequence or
 * a carriage return that rewrites the line ("Genba precheck: PASSED" behind
 * a CR reads as the last line) or a line break that starts a forged
 * `::error::` annotation. Control characters become spaces here, so the
 * text stays visible and the line stays one line.
 *
 * @package   Sayamaapps Genba
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-genba
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Text
{
	/** A cut text keeps this many characters, then "… (<n> characters)" (log_text of genba-smoke). */
	public const KEEP = 60;

	/** Above this many characters a text is cut (LOC_MAX of genba-smoke). */
	public const MAX = 2048;

	/** The mbstring functions the tools call (Text, Precheck, Links). */
	public const MBSTRING_FUNCTIONS = ['mb_scrub', 'mb_strlen', 'mb_strtolower', 'mb_substr'];

	/**
	 * Control characters (C0, DEL, C1 U+0080–U+009F) become spaces; invalid
	 * UTF-8 is scrubbed first, so the C1 pattern cannot match inside a
	 * broken sequence. A text above $max characters (after that) is cut to
	 * its first 60 with its length — a URL of 100 000 characters in a content
	 * file would otherwise be one log line of 100 073; genba-smoke's log_text
	 * cuts the same way. A caller that assembles a line of pieces it has cleaned
	 * one by one passes PHP_INT_MAX: the line itself is not cut then.
	 *
	 * mbstring is required (composer.json: ext-mbstring). There is no fallback
	 * without it: substr() would cut inside a UTF-8 character and count bytes
	 * as "characters".
	 */
	public static function clean(string $text, int $max = self::MAX): string
	{
		$text = mb_scrub($text, 'UTF-8');
		$text = (string)preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', ' ', $text);
		$length = mb_strlen($text, 'UTF-8');
		if ($length > $max) {
			$text = mb_substr($text, 0, self::KEEP, 'UTF-8') . "… ($length characters)";
		}
		return $text;
	}

	/**
	 * The cannot-run reason when mbstring is not usable, else null. composer.json requires
	 * ext-mbstring, but a runner's PHP can lack it — and a tool would then die with "Call to undefined
	 * function mb_substr()" in the middle of its run, without its last line (SPEC §3.0). Every PHP
	 * tool asks this before it starts: genba-stamp, genba-preflight, genba-precheck, genba-links and
	 * genba-smoke's reader. A function switched off with disable_functions counts as missing too.
	 */
	public static function missingMbstring(): string|null
	{
		if (!extension_loaded('mbstring')) {
			return 'PHP extension mbstring is missing';
		}
		foreach (self::MBSTRING_FUNCTIONS as $function) {
			if (!function_exists($function)) {
				return "PHP extension mbstring is missing ($function is not available)";
			}
		}
		return null;
	}
}
