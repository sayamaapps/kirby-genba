<?php if ($page->children()->count() > 0): ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">
<?php endif ?>
<p<?= Html::attr('style', 'color: ' . $page->color(), ' ') ?>>Text</p>
<button<?= attr(before: ' ', attr: [
	'type'    => 'button',
	'onclick' => 'go()',
]) ?>>Go</button>
<script<?= Html::attr('src', url('assets/js/app.js'), ' ') ?>></script>
<script<?= Html::attr(name: 'type', value: 'application/ld+json', before: ' ') ?>><?= json_encode($page->schema()) ?></script>
<script<?= Html::attr('src', $page->tracker()->value() ?: null, ' ') ?>>window.inline = <?= $page->num() ?>;</script>
<div<?= Html::attr('style', null, ' ') ?><?= Html::attr(name: 'onclick') ?>></div>
