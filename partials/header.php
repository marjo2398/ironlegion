<?php
$pageTitle = $pageTitle ?? 'Iron Legion';
$pageStyles = $pageStyles ?? '';
$pageHeadExtra = $pageHeadExtra ?? '';
$bodyClass = $bodyClass ?? '';
$viewport = $viewport ?? 'width=device-width, initial-scale=1.0';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang ?? 'en') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="<?= htmlspecialchars($viewport) ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <?= $pageHeadExtra ?>
    <?php if ($pageStyles): ?>
        <style>
<?= $pageStyles ?>
        </style>
    <?php endif; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
