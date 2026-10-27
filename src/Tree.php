<?php

namespace Sayamaapps\Genba;

use RuntimeException;

require_once __DIR__ . '/Text.php';

/**
 * The directory walk shared by the static checks (Precheck, Links): which
 * files are read, and — said out loud — which directories are not.
 * Kirby-free.
 *
 * Skipping is by PLACE, not by name: `site/cache` is Kirby's cache, but a
 * snippet folder `site/snippets/media/` or a page `content/media/` is site
 * code and is read. Only a few names that are never site code (`node_modules`,
 * `.git`) are skipped at any depth.
 *
 * Symbolic links to directories are followed when their target lies inside
 * the project, and reported otherwise. A link to a directory that was already
 * entered (an ancestor, a loop) is reported too, so the walk always ends. A
 * link INTO a skipped place (`site/snippets/c -> ../cache`), or a directory
 * reached through a link that lies inside one (`public/p -> ..` reaching
 * `site/cache`), is reported and not entered: the places are compared by their
 * real path, not only by name. A file reached twice (through a link and
 * through its real place) is read once.
 *
 * A symbolic link to a FILE is treated the same way: a target
 * outside the project is `symbolic link to a file outside the project`, one
 * inside a skipped place `symbolic link into <place>` — reported, never read,
 * so no foreign file content reaches the log.
 *
 * A directory that can be listed but not searched (read without execute
 * permission) yields names whose details cannot be read: each is reported as
 * `cannot be read (permission denied)`, never dropped without a word. A
 * directory that cannot be listed at all throws `Cannot read directory: <dir>`,
 * the directory relative to $project and as log text (an absolute path with
 * an ESC in its name would reach stderr and stdout raw).
 *
 * @package   Sayamaapps Genba
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-genba
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Tree
{
	/**
	 * The place (as shown) that $real equals or lies inside, or null.
	 *
	 * @param array<string,string> $places real path => place as shown
	 */
	private static function inside(string $real, array $places): string|null
	{
		foreach ($places as $place => $shown) {
			if ($real === $place || str_starts_with($real, $place . '/')) {
				return $shown;
			}
		}
		return null;
	}

	/**
	 * @param string $root directory to walk
	 * @param string $base directory the $skip paths are relative to (lexically, no realpath)
	 * @param array<string,string> $skip path relative to $base => reason; that one directory is not entered
	 * @param array<string,string> $anywhere directory name => reason; skipped at any depth
	 * @param string $project symbolic links are followed only when their target is inside it
	 * @param string|null $package realpath of a directory that is never entered (Genba's own package)
	 * @param array<string,string> $elsewhere absolute path => reason: further places never entered, compared by
	 *                                        real path only (the skip places of another walk's root)
	 * @param array<string,bool> $read real paths of files already listed, by reference: a caller that walks
	 *                                 several roots passes the same array, so a file reached through a
	 *                                 link in a later root (public/p -> ..) is listed once per run
	 * @return array{files: list<string>, skipped: list<array{dir: string, reason: string, own: bool}>}
	 *   files in walk order (sorted per directory); skipped entries as walked paths
	 */
	public static function walk(string $root, string $base, array $skip, array $anywhere, string $project, string|null $package, array $elsewhere = [], array &$read = []): array
	{
		$root = rtrim($root, '/');
		$base = rtrim($base, '/');
		$projectReal = realpath($project) ?: rtrim($project, '/');
		$files = [];
		$skipped = [];
		$entered = [];

		// The real path of every skip place that exists, once: real path => the place as shown.
		$places = [];
		$candidates = [];
		foreach ($skip as $relative => $reason) {
			$candidates[$base . '/' . $relative] = $relative;
		}
		foreach ($elsewhere as $path => $reason) {
			$candidates[rtrim($path, '/')] = $path;
		}
		foreach ($candidates as $path => $shown) {
			$real = realpath($path);
			if ($real === false) {
				continue;
			}
			$places[$real] = str_starts_with($real, $projectReal . '/') ? substr($real, strlen($projectReal) + 1) : (string)$shown;
		}

		$projectGiven = rtrim($project, '/');
		$walk = static function (string $dir) use (&$walk, &$files, &$skipped, &$entered, &$read, $base, $skip, $anywhere, $projectGiven, $projectReal, $package, $places): void {
			$real = realpath($dir);
			if ($real === false) {
				return;
			}
			$entered[$real] = true;
			$names = @scandir($dir);
			if ($names === false) {
				// Relative to the project as given (the walked path is built from it), else
				// to its real path; the name is the site's own, so it is log text.
				$shown = $dir;
				foreach ([$projectGiven, $projectReal] as $prefix) {
					if ($prefix !== '' && str_starts_with($dir, $prefix . '/')) {
						$shown = substr($dir, strlen($prefix) + 1);
						break;
					}
				}
				throw new RuntimeException('Cannot read directory: ' . Text::clean($shown));
			}
			foreach ($names as $name) {
				if ($name === '.' || $name === '..') {
					continue;
				}
				$path = $dir . '/' . $name;
				if (@lstat($path) === false) {
					// Listed, but its details cannot be read: the directory lacks search permission.
					$skipped[] = ['dir' => $path, 'reason' => 'cannot be read (permission denied)', 'own' => false];
					continue;
				}
				if (is_link($path) && !file_exists($path)) {
					$skipped[] = ['dir' => $path, 'reason' => 'broken symbolic link', 'own' => false];
					continue;
				}
				if (!is_dir($path)) {
					$target = realpath($path);
					if (!is_file($path) || $target === false) {
						continue;
					}
					if (is_link($path)) {
						// The same rules as for a linked directory: never a skipped place, never outside.
						$into = self::inside($target, $places);
						if ($into !== null) {
							$skipped[] = ['dir' => $path, 'reason' => "symbolic link into $into", 'own' => false];
							continue;
						}
						if (!str_starts_with($target, $projectReal . '/')) {
							$skipped[] = ['dir' => $path, 'reason' => 'symbolic link to a file outside the project', 'own' => false];
							continue;
						}
					}
					if (!isset($read[$target])) {
						$read[$target] = true;
						$files[] = $path;
					}
					continue;
				}
				$relative = str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : null;
				$reason = $anywhere[$name] ?? ($relative !== null ? ($skip[$relative] ?? null) : null);
				if ($reason !== null) {
					$skipped[] = ['dir' => $path, 'reason' => $reason, 'own' => false];
					continue;
				}
				$target = realpath($path);
				if ($target === false) {
					$skipped[] = ['dir' => $path, 'reason' => 'cannot be resolved', 'own' => false];
					continue;
				}
				if ($package !== null && $target === $package) {
					$skipped[] = ['dir' => $path, 'reason' => '', 'own' => true];
					continue;
				}
				$into = self::inside($target, $places);
				if ($into !== null) {
					$skipped[] = ['dir' => $path, 'reason' => is_link($path) ? "symbolic link into $into" : "inside $into, reached through a symbolic link", 'own' => false];
					continue;
				}
				if (is_link($path)) {
					if ($target !== $projectReal && !str_starts_with($target, $projectReal . '/')) {
						$skipped[] = ['dir' => $path, 'reason' => 'symbolic link to a directory outside the project', 'own' => false];
						continue;
					}
					if (isset($entered[$target])) {
						$skipped[] = ['dir' => $path, 'reason' => 'symbolic link to a directory already scanned', 'own' => false];
						continue;
					}
				}
				$walk($path);
			}
		};
		$walk($root);
		return ['files' => $files, 'skipped' => $skipped];
	}
}
