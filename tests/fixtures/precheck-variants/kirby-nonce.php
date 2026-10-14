<?php if ($page->children()->count() > 0): ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">
<?php endif ?>
<p class="<?= $page->isOpen() ? 'open' : 'closed' ?>" style="color:red">Text</p>
<button data-id="<?= $page->id() ?>" onclick="go()">Go</button>
<script nonce="<?= $kirby->nonce() ?>" src="<?= url('assets/js/app.js') ?>"></script>
<script nonce=<?= $kirby->nonce() ?> src="<?= url('assets/js/vendor.js') ?>"></script>
<script nonce="<?= $kirby->nonce() ?>" type="application/ld+json"><?= json_encode($page->schema()) ?></script>
<script nonce="<?= $kirby->nonce() ?>">window.inline = <?= $page->num() ?>;</script>
