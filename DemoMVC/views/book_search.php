<?php
function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PHP PDO Demo</title>
</head>
<body>
    <h1>PHP PDO Demo</h1>
    <form method="post">
        <label for="id">Nhập ID của book:</label>
        <input type="text" name="id" id="id" value="<?= h(is_string($_POST['id'] ?? null) ? $_POST['id'] : '') ?>">
        <button type="submit">Tìm kiếm</button>
    </form>
    <?php if ($error !== ""): ?>
        <script>alert(<?= json_encode($error,JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS) ?>);</script>
    <?php endif; ?>
    <?php if ($success !== ""): ?>
        <script>alert(<?= json_encode($success,JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS) ?>);</script>
    <?php endif; ?>
    <?php if ($book): ?>
        <h2>Thông tin book</h2>
        <p>ID: <?= h($book['id']) ?></p>
        <p>Title: <?= h($book['title']) ?></p>
        <p>Status: <?= h($book['status']) ?></p>
        <?php if ($book['status'] === 'available'): ?>
            <form method="post">
                <input type="hidden" name="id" value="<?= h($book['id']) ?>">
                <input type="hidden" name="action" value="reserved">
                <button type="submit">Cập nhật trạng thái</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
