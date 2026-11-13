<?php
function h(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}
?>
<html>
<head>
    <title>Storage Management</title>
</head>
<body>
    <?php if ($error!== ""): ?>
        <script>alert(<?= json_encode($error,JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);</script>
    <?php endif; ?>
    <?php if ($message!== ""): ?>
        <script>alert(<?= json_encode($message,JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);</script>
    <?php endif; ?>
    <h1>Storage Management</h1>
    <h2>Danh sách sản phẩm</h2>
    <table>
        <tr>
            <th>Mã sản phẩm</th>
            <th>Tên sản phẩm</th>
            <th>Đơn giá (VND)</th>
            <th>Ngưỡng tồn tối thiểu</th>
        </tr>
        <?php foreach ($products as $product): ?>
            <tr>
                <td><?= h($product['product_id']) ?></td>
                <td><?= h($product['product_name']) ?></td>
                <td><?= h($product['unit_price']) ?></td>
                <td><?= h($product['min_stock']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <h2>Danh sách kho</h2>
    <table>
        <tr>
            <th>Mã kho</th>
            <th>Tên kho</th>
        </tr>
        <?php foreach ($storages as $warehouse): ?>
            <tr>
                <td><?= h($warehouse['warehouse_id']) ?></td>
                <td><?= h($warehouse['warehouse_name']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <h2>Quản lý tồn kho theo từng kho</h2>
    <table>
        <tr>
            <th>Mã sản phẩm</th>
            <?php foreach($storages as $warehouse): ?>
                <th><?= h($warehouse['warehouse_id']) ?></th>
            <?php endforeach; ?>
            <th>Tổng tồn</th>
        </tr>
        <?php foreach($quantityManagement as $management): ?>
            <tr>
                <td><?= h($management['product_id']) ?></td>
                <?php foreach($management['storage_quantity'] as $storage=> $quantity): ?>
                    <td><?= h($quantity ?? 0) ?></td>
                <?php endforeach; ?>
                <td><?= h($management['total_quantity'] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td><strong>Tổng tồn kho</strong></td>
            <?php foreach($calculateWareHouseStock as $warehouse): ?>
                <td><strong><?= h($warehouse['total_quantity'] ?? 0) ?></strong></td>
            <?php endforeach; ?>
            <td><strong><?= h($inventoryStatistics['total_quantity_count'] ?? 0) ?></strong></td>
        </tr>
    </table>
    <h2>Giá trị tồn kho theo từng kho (VND)</h2>
    <?php foreach($storages as $warehouse): ?>
        <h3>Kho: <?= h($warehouse['warehouse_id']) ?> - <?= h($warehouse['warehouse_name']) ?></h3>
        <table>
            <tr>
                <th>Mã sản phẩm</th>
                <th>Số lượng tồn kho</th>
                <th>Đơn giá (VND)</th>
                <th>Giá trị tồn kho (VND)</th>
            </tr>
            <?php foreach($inventoryValue[$warehouse['warehouse_id']] as $product): ?>
                <tr>
                    <td><?= h($product['product_id']) ?></td>
                    <td><?= h($product['current_quantity'] ?? 0) ?></td>
                    <td><?= h($product['unit_price'] ?? 0) ?></td>
                    <td><?= h($product['total_value'] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td><strong>Tổng</strong></td>
                <?php foreach($calculateWareHouseStock as $value): ?>
                    <?php if($value['warehouse_id'] === $warehouse['warehouse_id']): ?>
                        <td><strong><?= h($value['total_quantity'] ?? 0) ?></strong></td>
                    <?php endif; ?>
                <?php endforeach; ?>
                <td></td>
                <td><strong><?= h($inventoryStatistics['total_quantity_value_storage'][$warehouse['warehouse_id']] ?? 0) ?></strong></td>
            </tr>
        </table>
    <?php endforeach; ?>

    <h2>Giao dịch kho hàng hóa (Nhập/Xuất/Chuyển)</h2>
    <form method="post">
        <label for="input">Nhập thông tin giao dịch:</label>
        <textarea id="input" name="input" rows="3" cols="30"></textarea>
        <br>
        <select name="type">
            <option value="import">Nhập kho</option>
            <option value="export">Xuất kho</option>
            <option value="transfer">Chuyển kho</option>
        </select>
        <br>
        <button type="submit" name="process">Xử lý</button>
    </form>
    <h2>Thống kê toàn bộ hệ thống</h2>
    <table>
        <tr><td>Tổng số sản phẩm: </td><td><?= h($inventoryStatistics['total_products'] ?? 0) ?></td></tr>
        <tr><td>Tổng số kho: </td><td><?= h($inventoryStatistics['total_warehouses'] ?? 0) ?></td></tr>
        <tr><td>Tổng số lượng tồn kho: </td><td><?= h($inventoryStatistics['total_quantity_count'] ?? 0) ?></td></tr>
        <tr><td>Tổng giá trị tồn kho (VND): </td><td><?= h($inventoryStatistics['total_quantity_value'] ?? 0) ?></td></tr>
        <tr><td>Tổng số lượng nhập: </td><td><?= h($inventoryStatistics['total_import_quantity'] ?? 0) ?></td></tr>
        <tr><td>Tổng số lượng xuất: </td><td><?= h($inventoryStatistics['total_export_quantity'] ?? 0) ?></td></tr>
        <tr><td>Tổng số lượng chuyển kho: </td><td><?= h($inventoryStatistics['total_transfer_quantity'] ?? 0) ?></td></tr>
        <tr><td>Số sản phẩm dưới hoăc bằng ngưỡng tồn tối thiểu: </td><td><?= h($inventoryStatistics['low_stock_product_count'] ?? 0) ?></td></tr>
    </table>
    <h2>Cảnh báo tồn kho thấp</h2>
    <h3>Danh sách sản phẩm dưới hoặc bằng ngưỡng tồn tối thiểu</h3>
    <table>
        <tr>
            <th>Mã sản phẩm</th>
            <th>Tên sản phẩm</th>
            <th>Ngưỡng tồn tối thiểu</th>
            <th>Tổng tồn kho</th>
        </tr>
        <?php foreach ($lowStockProducts as $product): ?>
            <tr>
                <td><?= h($product['product_id']) ?></td>
                <td><?= h($product['product_name']) ?></td>
                <td><?= h($product['min_stock']) ?></td>
                <td><?= h($product['total_current_quantity'] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <h2>Lịch sử giao dịch</h2>
    <table>
        <tr>
            <th>Mã giao dịch</th>
            <th>Mã sản phẩm</th>
            <th>Loại giao dịch</th>
            <th>Số lượng</th>
            <th>Kho nguồn</th>
            <th>Kho đích</th>
        </tr>
        <?php foreach ($transactionsHistory as $transaction): ?>
            <tr>
                <td><?= h($transaction['transaction_id']) ?></td>
                <td><?= h($transaction['product_id']) ?></td>
                <td><?= h($transaction['type']) ?></td>
                <td><?= h($transaction['quantity']) ?></td>
                <td><?= h($transaction['source_warehouse'] ?? '-') ?></td>
                <td><?= h($transaction['target_warehouse'] ?? '-') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <form method="post">
        <label for="product_id">Mã sản phẩm:</label>
        <input type="text" id="product_id" name="product_id" value="<?= h($_POST['product_id'] ?? '') ?>">
        <label for="warehouse_id">Mã kho:</label>
        <input type="text" id="warehouse_id" name="warehouse_id" value="<?= h($_POST['warehouse_id'] ?? '') ?>">
        <label for="transaction_id">Mã giao dịch:</label>
        <input type="text" id="transaction_id" name="transaction_id" value="<?= h($_POST['transaction_id'] ?? '') ?>">
        <label for="type">Loại giao dịch:</label>
        <select id="type" name="type">
            <option value="all" <?= (isset($_POST['type']) && $_POST['type'] === 'all') ? 'selected' : '' ?>>Tất cả</option>
            <option value="import" <?= (isset($_POST['type']) && $_POST['type'] === 'import') ? 'selected' : '' ?>>Nhập kho</option>
            <option value="export" <?= (isset($_POST['type']) && $_POST['type'] === 'export') ? 'selected' : '' ?>>Xuất kho</option>
            <option value="transfer" <?= (isset($_POST['type']) && $_POST['type'] === 'transfer') ? 'selected' : '' ?>>Chuyển kho</option>  
        </select>
        <button type="submit" name="filter">Lọc</button>
    </form>
</body>
</html>
