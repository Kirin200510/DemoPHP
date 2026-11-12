<?php
function h(string $string=null) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}
?>
<html>
<head>
    <title>Storage Management</title>
</head>
<body>
    <h1>Storage Product Management</h1>
    <h2>Danh sách sản phẩm</h2>
    <table>
        <tr>
            <th>Mã sản phẩm</th>
            <th>Tên sản phẩm</th>
            <th>Giá (VNĐ)</th>
            <th>Số lượng tồn kho ban đầu</th>
            <th>Số lượng tồn kho hiện tại</th>
            <th>Ngưỡng tối thiểu</th>
            <th>Trạng thái tồn kho</th>
        </tr>
        <?php foreach ($products as $product): ?>
            <tr>
                <td><?= h($product['product_id']); ?></td>
                <td><?= h($product['product_name']); ?></td>
                <td><?= h($product['unit_price']); ?></td>
                <td><?= h($product['quantity']); ?></td>
                <td><?= h($product['current_quantity']); ?></td>
                <td><?= h($product['min_stock']); ?></td>
                <td><?= h($product['stock_status']); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($error!==""):?>
        <script>alert(<?= json_encode($error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);</script>
    <?php endif; ?>
    <?php if ($message!==""):?>
        <script>alert(<?= json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);</script>
    <?php endif; ?>
    <!-- Quản lý nhập xuất kho -->
    <h2>Quản lý nhập xuất kho</h2>
    <form method="post">
        <label for="input">Nhập/Xuất kho:</label>
        <textarea id="input" name="input" rows="3" cols="30"></textarea>
        <br>
        <select name="type">
            <option value="import">Nhập kho</option>
            <option value="export">Xuất kho</option>
        </select>
        <br>
        <button type="submit" name="process">Xử lý</button>
    </form>
    <!-- Lọc và sắp xếp -->
     <h2>Lọc và sắp xếp</h2>
     <form method="post">
        <label for="product_id">Mã sản phẩm:</label>
        <input type="text" id="product_id" name="product_id" value="<?= h($_POST['product_id'] ?? ''); ?>">
        <label for="product_name">Tên sản phẩm:</label>
        <input type="text" id="product_name" name="product_name" value="<?= h($_POST['product_name'] ?? ''); ?>">
        <select name="status">
            <option value="all" <?= isset($_POST['status']) && $_POST['status'] === 'all' ? 'selected' : ''; ?>>Tất cả</option>
            <option value="In Stock" <?= isset($_POST['status']) && $_POST['status'] === 'In Stock' ? 'selected' : ''; ?>>In Stock</option>
            <option value="Low Stock" <?= isset($_POST['status']) && $_POST['status'] === 'Low Stock' ? 'selected' : ''; ?>>Low Stock</option>
        </select>
        <select name="sort">
            <option value="all" <?= isset($_POST['sort']) && $_POST['sort'] === 'all' ? 'selected' : ''; ?>>Tất cả</option>
            <option value="asc" <?= isset($_POST['sort']) && $_POST['sort'] === 'asc' ? 'selected' : ''; ?>>Tăng dần</option>
            <option value="desc" <?= isset($_POST['sort']) && $_POST['sort'] === 'desc' ? 'selected' : ''; ?>>Giảm dần</option>
        </select>
        <select name="type_sort">
            <option value="quantity" <?= isset($_POST['type_sort']) && $_POST['type_sort'] === 'quantity' ? 'selected' : ''; ?>>Số lượng tồn kho</option>
            <option value="value_stock" <?= isset($_POST['type_sort']) && $_POST['type_sort'] === 'value_stock' ? 'selected' : ''; ?>>Giá trị tồn kho</option>
        </select>
        <button type="submit" name="filter">Lọc</button>
    </form>
    <?php if (!empty($calculateStock)): ?>
    <h2>Số lượng tồn kho sau giao dịch</h2>
    <table>
        <tr>
            <th>Mã sản phẩm</th>
            <th>Tồn ban đầu</th>
            <th>Tổng nhập</th>
            <th>Tổng xuất</th>
            <th>Tồn hiện tại</th>
        </tr>
        <?php foreach ($calculateStock as $product => $stock): ?>
            <tr>
                <td><?= h($stock['product_id']); ?></td>
                <td><?= h($stock['quantity']); ?></td>
                <td><?= h($stock['total_import']); ?></td>
                <td><?= h($stock['total_export']); ?></td>
                <td><?= h($stock['current_stock']); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php endif;?>
    <?php if (!empty($stockStatistics)): ?>
        <h2>Thống kê tổng quan</h2>
        <table>
            <tr>
                <th>Chỉ tiêu</th>
                <th>Kết quả</th>
            </tr>
            <tr>
                <td>Tổng số loại sản phẩm</td>
                <td><?= h($stockStatistics['total_products']); ?></td>
            </tr>
            <tr>
                <td>Tổng số lượng tồn kho hiện tại</td>
                <td><?= h($stockStatistics['total_current_stock']); ?></td>
            </tr>
            <tr>
                <td>Tổng số lượng nhập</td>
                <td><?= h($stockStatistics['total_import']); ?></td>
            </tr>
            <tr>
                <td>Tổng số lượng xuất</td>
                <td><?= h($stockStatistics['total_export']); ?></td>
            </tr>

            <tr>
                <td>Tổng giá trị tồn kho hiện tại (VNĐ)</td>
                <td><?= h($stockStatistics['total_present_storage']); ?></td>
            </tr>
            <tr>
                <td>Số lượng sản phẩm Low Stock</td>
                <td><?= h($stockStatistics['low_stock_products']); ?></td>
            </tr>
        </table>
    <?php endif;?>
    <?php if (!empty($lowStockProducts)): ?>
        <h2>Danh sách sản phẩm Low Stock</h2>
        <table>
            <tr>
                <th>Mã sản phẩm</th>
                <th>Tên sản phẩm</th>
                <th>Tồn kho hiện tại</th>
                <th>Ngưỡng tối thiểu</th>
                <th>Cần bổ sung</th>
            </tr>
            <?php foreach ($lowStockProducts as $product): ?>
                <tr>
                    <td><?= h($product['product_id']); ?></td>
                    <td><?= h($product['product_name']); ?></td>
                    <td><?= h($product['current_quantity']); ?></td>
                    <td><?= h($product['min_stock']); ?></td>
                    <td><?= h($product['resupply']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif;?>
    <?php if (!empty($calculateStock)): ?>
        <h2>Giá trị tồn kho theo sản phẩm</h2>
        <table>
            <tr>
                <th>Mã sản phẩm</th>
                <th>Tồn hiện tại</th>
                <th>Đơn giá</th>
                <th>Giá trị tồn kho (VNĐ)</th>
            </tr>
            <?php foreach ($calculateStock as $product => $stock): ?>
                <tr>
                    <td><?= h($stock['product_id']); ?></td>
                    <td><?= h($stock['current_stock']); ?></td>
                    <td><?= h($stock['unit_price']); ?></td>
                    <td><?= h($stock['value_stock']); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td><strong>Tổng</strong></td>
                <td><strong><?= h($stockStatistics['total_current_stock']); ?></strong></td>
                <td></td>
                <td><strong><?= h($stockStatistics['total_present_storage']); ?></strong></td>
            </tr>
        </table>
    <?php endif;?>
    <?php if (!empty($transactionHistory)): ?>
        <h2>Kiểm tra giao dịch</h2>
        <p>Có <?= h($transactionHistory['total_import_transactions']); ?> giao dịch nhập kho thành công</p>
        <p>Có <?= h($transactionHistory['total_export_transactions']); ?> giao dịch xuất kho thành công</p>
        <p>Tổng số  lượng nhập là: <?= h($transactionHistory['total_import_quantity']); ?></p>
        <p>Tổng số lượng xuất là: <?= h($transactionHistory['total_export_quantity']); ?></p>
    <?php endif;?>
    <?php if (!empty($maxExportProducts)): ?>
        <h2>Sản phẩm có số lượng xuất kho nhiều nhất</h2>
        <table>
            <?php foreach ($maxExportProducts['max_export_products'] as $product): ?>
                <tr>
                    <td><?= h($product['product_id']); ?> - <?= h($product['product_name']); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td><strong>Tổng số lượng xuất nhiều nhất là: <?= h($maxExportProducts['max_export_count']); ?></strong></td>
            </tr>
        </table>
    <?php endif;?>
        <h2>Lịch sử giao dịch</h2>
        <table>
            <tr>
                <th>Mã giao dịch</th>
                <th>Mã sản phẩm</th>
                <th>Tên sản phẩm</th>
                <th>Loại giao dịch</th>
                <th>Số lượng</th>
                <th>Kết quả</th>
            </tr>
            <?php foreach ($transactionHistoryList as $transaction): ?>
                <tr>
                    <td><?= h($transaction['transaction_id']); ?></td>
                    <td><?= h($transaction['product_id']); ?></td>
                    <td><?= h($transaction['product_name']); ?></td>
                    <td><?= h($transaction['type']); ?></td>
                    <td><?= h($transaction['quantity']); ?></td>
                    <td><?= h($transaction['result']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <form method="post">
            <label for="product_id_transaction">Tìm kiếm giao dịch theo product id:</label>
            <input type="text" id="product_id_transaction" name="product_id_transaction">
            <label for="type_transaction">Loại giao dịch:</label>
            <select name="type_transaction" id="type_transaction">
                <option value="import">Nhập kho</option>
                <option value="export">Xuất kho</option>
            </select>
            <button type="submit" name="filter_transaction">Lọc lịch sử giao dịch</button>
        </form>
</body>
</html>
