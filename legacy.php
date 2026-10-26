<?php

/**
 * Names of the API before the Sayamaapps\Genba namespace, kept until the next major version (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Class aliases
 * only; no logic here.
 */

class_alias(\Sayamaapps\Genba\Homepage::class, 'Genba\\Homepage');
class_alias(\Sayamaapps\Genba\I18n::class, 'Genba\\I18n');
class_alias(\Sayamaapps\Genba\Links::class, 'Genba\\Links');
class_alias(\Sayamaapps\Genba\Log::class, 'Genba\\Log');
class_alias(\Sayamaapps\Genba\Options::class, 'Genba\\Options');
class_alias(\Sayamaapps\Genba\Precheck::class, 'Genba\\Precheck');
class_alias(\Sayamaapps\Genba\Preflight::class, 'Genba\\Preflight');
class_alias(\Sayamaapps\Genba\Stamp::class, 'Genba\\Stamp');
class_alias(\Sayamaapps\Genba\Text::class, 'Genba\\Text');
class_alias(\Sayamaapps\Genba\Tree::class, 'Genba\\Tree');
