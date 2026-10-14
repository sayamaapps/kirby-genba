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
if (!\Genba\Options::active($genbaKirby)) {
    return;
}
\Genba\Options::applyLanguage($genbaKirby);

$genbaShowLabel = isset($label) ? (bool) $label : true;
$genbaShowDate = isset($withDate) ? (bool) $withDate : false;
$genbaInfo = site()->genbaBuildInfo();

if ($genbaInfo === null) {
    ?>
<p class="genba-build genba-build--none"><?= esc(\Genba\I18n::t('build.none')) ?></p>
<?php
    return;
}
?>
<p class="genba-build" data-build="<?= esc($genbaInfo['hash']) ?>"><?php if ($genbaShowLabel): ?><span class="genba-build-label"><?= esc(\Genba\I18n::t('build.label')) ?></span> <?php endif ?><code class="genba-build-hash"><?= esc($genbaInfo['short']) ?></code><?php if ($genbaShowDate && $genbaInfo['writtenAt'] !== null): ?> <span class="genba-build-date"><?= esc(\Genba\I18n::t('build.writtenAt', ['date' => substr($genbaInfo['writtenAt'], 0, 10)])) ?></span><?php endif ?></p>
