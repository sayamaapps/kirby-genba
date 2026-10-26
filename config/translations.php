<?php

use Sayamaapps\Genba\I18n;

// Every string, usable as t('sayamaapps.genba.<key>') and as a blueprint label.
// The keys under the former prefix `genba.` stay registered until the next major version.
return (static function (): array {
	$translations = [];
	foreach (I18n::LANGUAGES as $language) {
		foreach (I18n::table($language) as $key => $text) {
			$translations[$language]['sayamaapps.genba.' . $key] = $text;
			$translations[$language]['genba.' . $key] = $text;
		}
	}
	return $translations;
})();
