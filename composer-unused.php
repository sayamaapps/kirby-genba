<?php

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

// Requirements the tool cannot see: the PHP version, the installer that places the
// plugin in site/plugins, and the extensions whose functions (not classes) the code
// calls: mbstring, curl, json, dom, ctype, filter, libxml and tokenizer.
return static function (Configuration $configuration): Configuration {
	return $configuration
		->addNamedFilter(NamedFilter::fromString('php'))
		->addNamedFilter(NamedFilter::fromString('ext-mbstring'))
		->addNamedFilter(NamedFilter::fromString('ext-curl'))
		->addNamedFilter(NamedFilter::fromString('ext-json'))
		->addNamedFilter(NamedFilter::fromString('ext-dom'))
		->addNamedFilter(NamedFilter::fromString('ext-ctype'))
		->addNamedFilter(NamedFilter::fromString('ext-filter'))
		->addNamedFilter(NamedFilter::fromString('ext-libxml'))
		->addNamedFilter(NamedFilter::fromString('ext-tokenizer'))
		->addNamedFilter(NamedFilter::fromString('getkirby/composer-installer'));
};
