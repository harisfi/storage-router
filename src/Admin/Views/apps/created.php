<?php
/**
 * @var string $appId
 * @var string $rawKey
 * @var string $name
 */
$appIdEsc = htmlspecialchars($appId, ENT_QUOTES, 'UTF-8');
$rawKeyEsc = htmlspecialchars($rawKey, ENT_QUOTES, 'UTF-8');
$nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
?>
<div class="error" style="background:#fff8e1;border-color:#e0c68a;">
    <strong>Save this API key now — it will not be shown again.</strong>
    Only its SHA-256 hash is stored.
</div>
<p><strong>App:</strong> <?= $nameEsc ?> (<code><?= $appIdEsc ?></code>)</p>
<p><strong>API key:</strong> <code><?= $rawKeyEsc ?></code></p>
<p>Send it as: <code>X-API-Key: <?= $rawKeyEsc ?></code></p>
<p><a href="/admin/apps">Back to apps</a></p>
