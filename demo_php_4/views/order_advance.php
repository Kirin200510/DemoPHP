<?php
    function h($string) {
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
        <title>Order Advance</title>
    </head>
    <body>
        <h1>Order Advance</h1>
        <p>Danh sách đơn hàng</p>
        <table>
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer Name</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?=h($order['order_id']); ?></td>
                        <td><?=h($order['customer_name']); ?></td>
                        <td><?=h($order['amount']); ?></td>
                        <td><?=h(statusVN($order['status'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($error!==""): ?>
            <script>alert(<?=json_encode($error); ?>);</script>
        <?php endif; ?>
        <?php if ($message  !== ""): ?>
            <script>alert(<?=json_encode($message); ?>);</script>
        <?php endif; ?>
        <form method="POST">
            <label for="input">Add Order:</label>
            <textarea name="input" placeholder="Enter order details"><?=h($_POST['input'] ?? ''); ?></textarea>
            <button type="submit">Add</button>
        </form>
        <form method="POST">
            <h2>Filter Orders</h2>
            <label for="customer_name">Filter by Customer Name:</label>
            <input type="text" name="customer_name" value="<?=h($_POST['customer_name'] ?? ''); ?>">
            </br>
            <label for="status">Filter by Status:</label>
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
                    </br>
            <label for="min_amount">Filter by Amount:</label>
            <input type="number" name="min_amount" placeholder="Min Amount" value="<?=h($_POST['min_amount'] ?? ''); ?>">
            <input type="number" name="max_amount" placeholder="Max Amount" value="<?=h($_POST['max_amount'] ?? ''); ?>">
            <button type="submit" name="filter">Filter</button>
        </form>
        <form method="post">
            <h1> Sắp xếp đơn hàng theo giá trị</h1>
            <select name="sort">
                <option value="all" <?= (isset($_POST['sort']) && $_POST['sort'] === 'all') ? 'selected' : ''; ?>>Tất cả</option>
                <option value="asc" <?= (isset($_POST['sort']) && $_POST['sort'] === 'asc') ? 'selected' : ''; ?>>Tăng dần</option>
                <option value="desc" <?= (isset($_POST['sort']) && $_POST['sort'] === 'desc') ? 'selected' : ''; ?>>Giảm dần</option>
            </select>
            <button type="submit">Sắp xếp</button>
        </form>
        <?php if (!empty($statistics)): ?>
            <h2>Thống kê</h2>
            <p>Tổng số đơn hàng: <?= h($statistics['total_orders']); ?></p>
            <p>Tổng giá trị tất cả đơn hàng: <?= h($statistics['total_amount']); ?></p>
            <p>Số đơn pending: <?= h($statistics['pending_count']); ?></p>
            <p>Số đơn completed: <?= h($statistics['completed_count']); ?></p>
            <p>Số đơn cancelled: <?= h($statistics['cancelled_count']); ?></p>
            <p>Số đơn processing: <?= h($statistics['processing_count']); ?></p>
            <p>Doanh thu thực tế: <?= h($statistics['revenue']); ?></p>
            <p>Tỷ lệ đơn hàng theo trạng thái:</p>
            <ul>
                <?php foreach($statistics['percentage_statuses'] as $status => $percentage): ?>
                    <li><?= h(statusVN($status)); ?>: <?= h($percentage); ?>%</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($statisticCustomer)): ?>
            <h2>Thống kê theo khách hàng</h2>
            <table>
                <thead>
                    <tr>
                        <th>Tên khách hàng</th>
                        <th>Tổng số đơn hàng</th>
                        <th>Đơn hàng hoàn thành</th>
                        <th>Đơn hàng hủy</th>
                        <th>Tổng doanh thu</th>
                        <th>Phân loại</th>
                        <th>Tỷ lệ hủy</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statisticCustomer as $customer => $data): ?>
                        <tr>
                            <td><?= h($customer); ?></td>
                            <td><?= h($data['total_orders']); ?></td>
                            <td><?= h($data['complete_orders']); ?></td>
                            <td><?= h($data['cancelled_orders']); ?></td>
                            <td><?= h($data['revenue']); ?></td>
                            <td><?= h($data['classification']); ?></td>
                            <td><?= h($data['percent_cancelled']); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($maxPercentCancelledCustomer)): ?>
            <h2>Khách hàng có tỷ lệ hủy cao nhất</h2>
            <?php foreach ($maxPercentCancelledCustomer as $customer => $data): ?>
                <?php foreach ($data as $key => $value): ?>
                    <p>Tên khách hàng: <?= h($value); ?></p>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <p>Tỷ lệ hủy: <?= h($maxPercentCancelledCustomer['percent']); ?>%</p>
        <?php endif; ?>
        <?php if(!empty($topRevenueCustomers)): ?>
            <h2>Top 3 khách hàng có doanh thu cao nhất</h2>
            <table>
                <thead>
                    <tr>
                        <th>Tên khách hàng</th>
                        <th>Tổng doanh thu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($topRevenueCustomers as $customer => $revenue): ?>
                        <tr>
                            <td><?= h($customer); ?></td>
                            <td><?= h($revenue); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if(!empty($topAmountOrders)): ?>
            <h2>Top 3 đơn hàng có giá trị cao nhất</h2>
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer Name</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($topAmountOrders as $order): ?>
                        <tr>
                            <td><?= h($order['order_id']); ?></td>
                            <td><?= h($order['customer_name']); ?></td>
                            <td><?= h($order['amount']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if(!empty($abnormalOrders)): ?>
            <h2>Kết quả bất thường</h2>
            <p>Khách hàng có 2 đơn hủy trở lên: <?=h(implode(', ', array_keys($abnormalOrders[1]))); ?></p>
            <p>Đơn hàng có giá trị lớn hơn 3000000: <?=h(implode(', ', array_column($abnormalOrders[0], 'order_id'))); ?></p>
            <p>Mã đơn hàng trùng lặp: <?=h(implode(', ', $abnormalOrders[2])); ?></p>
        <?php endif; ?>
    </body>
</html>