<?php
function h(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}
?>
<html>
<head>
    <title>Convert</title>
    </head>
<body>  
    <form method="post">
        <label for="input">Nhập năm:</label>
        <input type="text" name="input" id="input" value="<?= h(is_string($_POST['input']) ? $_POST['input'] : '') ?>">
        <label for="nam">Nhập năm để tìm Can Chi trùng:</label>
        <input type="text" name="nam" id="nam" value="<?= h(is_string($_POST['nam']) ? $_POST['nam'] : '') ?>">
        <button type="submit">Convert</button>
    </form>
    <?php if ($error !== ''): ?>
        <script>alert(<?= json_encode($error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);</script>
    <?php endif; ?>
    <?php if (!empty($results)): ?>
        <h2>Kết quả chuyển đổi:</h2>
        <ul>
            <?php foreach ($results as $year => $canChi): ?>
                <li><?= h($year) ?>: <?= h($canChi) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if (!empty($statistic)): ?>
        <h2>Thống kê:</h2>
        <ul>
            <?php foreach ($statistic as $statistic => $count): ?>
                <li><?= h($statistic) ?>: <?= h($count) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if (!empty($sameCanChiYears)): ?>
        <h2>Các năm có Can Chi trùng với năm <?= h($nam) ?>:</h2>
        <ul>
            <?php foreach ($sameCanChiYears as $year): ?>
                <li><?= h($year) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</body>
</html>