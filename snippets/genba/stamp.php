<?php
/**
 * Build stamp for <head>: <meta name="build" content="<hash>"> when
 * genba.active is true and the stamp file holds a valid hash, nothing
 * otherwise (SPEC.md §3.6). The content is only ever a hash that matched
 * Stamp::PATTERN, so it needs no escaping.
 */
$genbaKirby = kirby();
if (!\Genba\Options::active($genbaKirby)) {
    return;
}
echo \Genba\Stamp::meta(site()->genbaBuild(), \Genba\Options::metaName($genbaKirby));
