<?php

/**
 * Class map for Kirby's F::loadClasses(). Keys are lowercase class names,
 * values are paths relative to the plugin root. AutoloadTest checks it
 * against src/.
 */
return [
	'sayamaapps\\genba\\homepage' => 'src/Homepage.php',
	'sayamaapps\\genba\\i18n'     => 'src/I18n.php',
	'sayamaapps\\genba\\links'    => 'src/Links.php',
	'sayamaapps\\genba\\log'      => 'src/Log.php',
	'sayamaapps\\genba\\options'  => 'src/Options.php',
	'sayamaapps\\genba\\precheck' => 'src/Precheck.php',
	'sayamaapps\\genba\\preflight' => 'src/Preflight.php',
	'sayamaapps\\genba\\stamp'    => 'src/Stamp.php',
	'sayamaapps\\genba\\text'     => 'src/Text.php',
	'sayamaapps\\genba\\tree'     => 'src/Tree.php',
];
