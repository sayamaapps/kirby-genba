<?php
/**
 * A visible build line, e.g. in a preview footer (SPEC.md §3.6). Nothing when
 * genba.active is not true.
 *
 * Optional variables:
 *   $label    bool, default true: prefix "Build" / "Stand"
 *   $withDate bool, default false: "deployed <date>" from the stamp file's mtime
 *
 * Only genba-* classes; texts from the plugin's translations, every value escaped.
 */
$genbaKirby = kirby();
if (!\Sayamaapps\Genba\Options::active($genbaKirby)) {
	return;
}
\Sayamaapps\Genba\Options::applyLanguage($genbaKirby);

$genbaShowLabel = isset($label) ? (bool)$label : true;
$genbaShowDate = isset($withDate) ? (bool)$withDate : false;
$genbaInfo = site()->genbaBuildInfo();

if ($genbaInfo === null) {
	?>
<p class="genba-build genba-build--none"><?= esc(\Sayamaapps\Genba\I18n::t('build.none')) ?></p>
<?php
	return;
}
$genbaLabel = $genbaShowLabel
	? '<span class="genba-build-label">' . esc(\Sayamaapps\Genba\I18n::t('build.label')) . '</span> '
	: '';
$genbaDate = $genbaShowDate && $genbaInfo['writtenAt'] !== null
	? ' <span class="genba-build-date">'
		. esc(\Sayamaapps\Genba\I18n::t('build.writtenAt', ['date' => substr($genbaInfo['writtenAt'], 0, 10)]))
		. '</span>'
	: '';
?>
<p class="genba-build" data-build="<?= esc($genbaInfo['hash']) ?>"><?= $genbaLabel ?><code class="genba-build-hash"><?= esc($genbaInfo['short']) ?></code><?= $genbaDate ?></p>
