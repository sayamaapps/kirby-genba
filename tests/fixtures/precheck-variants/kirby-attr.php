<?php if ($page->children()->count() > 0): ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">
<?php endif ?>
<p<?= attr(['class' => 'lead'], ' ') ?> style="color:red">Text</p>
<button<?= attr(['data-id' => $page->id()], ' ') ?> onclick="go()">Go</button>
<script<?= attr(['src' => url('assets/js/app.js'), 'nonce' => $kirby->nonce(), 'defer' => true], ' ') ?>></script>
<script <?= Html::attr(['type' => 'application/ld+json', 'nonce' => $kirby->nonce()]) ?>><?= json_encode($page->schema()) ?></script>
<script<?= attr(['nonce' => $kirby->nonce()], ' ') ?>>window.inline = <?= $page->num() ?>;</script>
