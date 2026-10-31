<?php

// Options Genba reads, relative to the plugin: `sayamaapps.genba.<key>`.
// Each value is null on purpose. Options::get() gives the default at every read,
// and a default here would hide the 1.x spelling `genba.<key>` (fallback).
return [
	'active'     => null,
	'language'   => null,
	'stamp.file' => null,
	'stamp.meta' => null,
];
