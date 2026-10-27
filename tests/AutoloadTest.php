<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The class map in autoload.php matches src/: one entry per class file, the
 * file declares the namespace and the class, and every entry loads.
 */
// autoload.php is an array, not a class: nothing to cover.
#[CoversNothing]
final class AutoloadTest extends TestCase
{
	public function testClassMapListsEveryClassFileOfSrc(): void
	{
		$root = dirname(__DIR__);
		$map = require $root . '/autoload.php';

		$expected = [];
		foreach (glob($root . '/src/*.php') ?: [] as $file) {
			$class = pathinfo($file, PATHINFO_FILENAME);
			$expected['sayamaapps\\genba\\' . strtolower($class)] = 'src/' . basename($file);

			$code = (string)file_get_contents($file);
			holds(str_contains($code, "namespace Sayamaapps\\Genba;\n"), "$class: declares the namespace Sayamaapps\\Genba");
			holds(preg_match('/^(?:final |abstract )?(?:class|interface|trait) ' . $class . '\b/m', $code) === 1, "$class: the file declares the class of the same name");
		}

		ksort($expected);
		$actual = $map;
		ksort($actual);
		expect($expected, $actual, 'autoload.php lists each src/ class file once, with its path');
	}

	public function testEveryEntryLoadsItsClass(): void
	{
		$root = dirname(__DIR__);
		$map = require $root . '/autoload.php';

		foreach ($map as $class => $path) {
			holds(is_file($root . '/' . $path), "$path exists");
			holds(class_exists('Sayamaapps\\Genba\\' . substr($class, strlen('sayamaapps\\genba\\')), true), "$class loads through the class map");
		}
	}
}
