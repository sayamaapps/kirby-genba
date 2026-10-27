<?php

use Sayamaapps\Genba\I18n;

// Every string, usable as t('genba.<key>') and as a blueprint label.
return (static function (): array {
	$translations = [];
	foreach (I18n::LANGUAGES as $language) {
		foreach (I18n::table($language) as $key => $text) {
			$translations[$language]['genba.' . $key] = $text;
		}
	}
	return $translations;
})();
