<?php
function h(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}
function statusVN(string $status): string {
    $statusMap = [
        'pending' => 'Đang chờ xử lý',
        'processing' => 'Đang xử lý',
        'completed' => 'Đã hoàn thành',
        'cancelled' => 'Đã hủy'
    ];
    return $statusMap[$status] ?? $status;
}
?>
<html>
<head>
    <title>Order Statistics</title>
</head>
<body>

    <form method="post" action="">
        <h1> Nhập danh sách đơn hàng </h1>
        <textarea name="orders" rows="10" cols="50"><?= h($_POST['orders'] ?? ''); ?></textarea>
        <button type="submit">Thống kê</button>
    </form>
    <form method="post">
        <h1> Lọc đơn hàng theo trạng thái </h1>
        <input type="hidden" name="orders" value="<?= h($_POST['orders'] ?? ''); ?>">
        <input type="hidden" name="sort" value="<?= h($_POST['sort'] ?? 'all'); ?>">
        <input type="hidden" name="status" value="<?= h($_POST['status'] ?? ''); ?>">
        
        <select name="status">
            <option value="all" <?= (isset($_POST['status']) && $_POST['status'] === 'all') ? 'selected' : ''; ?>>Tất cả</option>
            <?php foreach($statuses as $status): ?>
                <?php if (isset($_POST['status']) && $_POST['status'] === $status): ?>
                    <option value="<?= h($status); ?>" selected><?= h(statusVN($status)); ?></option>
                <?php else: ?>
                    <option value="<?= h($status); ?>"><?= h(statusVN($status)); ?></option>
                <?php endif; ?>
            <?php endforeach; ?>   
        </select>
        
        <button type="submit">Lọc</button>
    </form>
    <form method="post">
        <h1> Sắp xếp đơn hàng theo giá trị </h1>
        <input type="hidden" name="orders" value="<?= h($_POST['orders'] ?? ''); ?>">
        <input type="hidden" name="sort" value="<?= h($_POST['sort'] ?? ''); ?>">
        <input type="hidden" name="status" value="<?= h($_POST['status'] ?? ''); ?>">
        <select name="sort">
            <option value="all" <?= (isset($_POST['sort']) && $_POST['sort'] === 'all') ? 'selected' : ''; ?>>Tất cả</option>
            <option value="asc" <?= (isset($_POST['sort']) && $_POST['sort'] === 'asc') ? 'selected' : ''; ?>>Tăng dần</option>
            <option value="desc" <?= (isset($_POST['sort']) && $_POST['sort'] === 'desc') ? 'selected' : ''; ?>>Giảm dần</option>
        </select>
        <button type="submit">Sắp xếp</button>
    </form>
    <h1> Danh sách đơn hàng </h1>
    <table border="1">
        <tr>
            <th>Mã đơn hàng</th>
            <th>Tên khách hàng</th>
            <th>Giá trị đơn hàng</th>
            <th>Trạng thái</th>
        </tr>
        <?php foreach ($orders as $order): ?>
            <tr>
                <td><?= h($order['order_id']); ?></td>
                <td><?= h($order['customer_name']); ?></td>
                <td><?= h($order['amount']); ?></td>
                <td><?= h(statusVN($order['status'])); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($results): ?>
        <h2> Thống kê đơn hàng </h2>
        <p>Tổng số đơn hàng: <?= h($results['total_orders']); ?></p>
        <p>Tổng giá trị đơn hàng: <?= h($results['total_amount']); ?></p>
        <p>Số lượng đơn hàng đang chờ xử lý: <?= h($results['pending']); ?></p>
        <p>Số lượng đơn hàng đang xử lý: <?= h($results['processing']); ?></p>
        <p>Số lượng đơn hàng đã hoàn thành: <?= h($results['completed']); ?></p>
        <p>Số lượng đơn hàng đã hủy: <?= h($results['cancelled']); ?></p>
        <p>Tổng giá trị đơn hàng đã hoàn thành: <?= h($results['total_amount_completed']); ?></p>
        <p>Đơn hàng có giá trị lớn nhất: <?= h($results['max_amount_order']); ?></p>    
    <?php endif; ?>
    <?php if (!empty($secondMaxOrder)): ?>
        <p><strong>Đơn hàng có giá trị lớn thứ hai: <?= h($secondMaxOrder['order_id']); ?> - <?= h($secondMaxOrder['amount']); ?></strong> </p>
    <?php endif; ?>
    <?php if (!empty($typeCustomers)): ?>
        <h2> Phân loại khách hàng </h2>
        <?php foreach ($typeCustomers as $customerName => $customerType): ?>
            <p><strong>Tên khách hàng: <?= h($customerName); ?>  - Loại: <?= h($customerType); ?></strong></p>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($error!==""): ?>
        <script>alert(<?= json_encode($error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);</script>
    <?php endif; ?>
</body>
</html>