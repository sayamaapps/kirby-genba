<?php

// Plain defaults without CSS. A site with its own markup creates
// site/snippets/genba/<name>.php, which Kirby looks at first.
return [
	'genba/stamp'      => dirname(__DIR__) . '/snippets/genba/stamp.php',
	'genba/build-info' => dirname(__DIR__) . '/snippets/genba/build-info.php',
];
