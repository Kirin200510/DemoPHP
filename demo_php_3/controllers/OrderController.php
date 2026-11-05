<?php
class Controller {
    // mảng tiếng việt và tiếng anh
    private $status=["pending","processing","completed","cancelled"];
    public function convertStringToArray(string $input):array {
        $lines = explode("\n", $input);
        $orders = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $parts = explode(',', $line);
                if (count($parts) === 4) {
                    $orders[] = [
                        'order_id' => trim($parts[0]),
                        'customer_name' => trim($parts[1]),
                        'amount' => trim($parts[2]),
                        'status' => trim($parts[3])
                    ];
                } else { 
                    return []; // Return an empty array if the line doesn't have exactly 4 parts
                }
            }
        }
        return $orders;
    }
    public function validateAmountRange(string $amount):bool {
        $amount=trim($amount);
        $amount=ltrim($amount, '0');
        $validateAmount=filter_var($amount, FILTER_VALIDATE_INT,[
            'options' => [
                'min_range' => 1
            ]
        ]);
        return $validateAmount !== false;
    }
    // mảng gồm nhiều order mỗi order có mảng con thì khai báo kiểu dữ liệu là array ví dụ:   
    public function validateInputOrders(array $orders):string {
        if (empty($orders)) {
            return 'Dữ liệu đơn hàng không hợp lệ';
        }
        foreach ($orders as $order) {
            if (trim($order['order_id']) === '') {
                return 'Mã đơn hàng không được để trống';
            } // trùng mã orderid trước đó
            elseif (array_count_values(array_column($orders, 'order_id'))[$order['order_id']] > 1) {
                return 'Mã đơn hàng bị trùng';
            }
            elseif (trim($order['customer_name']) === '') {
                return 'Tên khách hàng không được để trống';
            } else if (!preg_match('/^[\p{L}\s]+$/u', trim($order['customer_name']))) {
                return 'Tên khách hàng chỉ được chứa chữ cái và khoảng trắng giữa các từ';
            } else if (!ctype_digit(trim($order['amount']))) {
                return 'Giá trị đơn hàng phải là số nguyên';
            } else if (!$this->validateAmountRange($order['amount'])) {
                return 'Số lượng phải là số nguyên dương lớn hơn 0';
            } else if (!in_array(trim($order['status']), $this->status)) {
                return 'Trạng thái đơn hàng không hợp lệ';
            }
        }   
        return 'Valid input orders';
    }

    public function parseOrders(array $orders):array {
        $parsedOrders = [];
        foreach ($orders as $order) {
            $parsedOrders[] = [
                'order_id' => trim($order['order_id']),
                'customer_name' => trim($order['customer_name']),
                'amount' => (int)trim($order['amount']),
                'status' => trim($order['status'])
            ];
        }
        return $parsedOrders;
    }
    public function getStatisticOrders(array $orders):array {
        $totalOrders = count($orders);
        $totalAmount = array_sum(array_column($orders, 'amount'));
        $statusPendingCount=array_count_values(array_column($orders, 'status'))['pending'] ?? 0;
        $statusProcessingCount=array_count_values(array_column($orders, 'status'))['processing'] ?? 0;
        $statusCompletedCount=array_count_values(array_column($orders, 'status'))['completed'] ?? 0;
        $statusCancelledCount=array_count_values(array_column($orders, 'status'))['cancelled'] ?? 0;
        $totalAmountComplete=array_sum(array_column(array_filter($orders, function($order) {
            return $order['status'] === 'completed';
        }), 'amount'));
        $maxAmountOrder = max(array_column($orders, 'amount'));
        $maxAmountOrderDetail=array_filter($orders, function($order) use ($maxAmountOrder) {
            return $order['amount'] === $maxAmountOrder;
        });
        
        return [
            'total_orders' => $totalOrders,
            'total_amount' => $totalAmount,
            'pending' => $statusPendingCount,
            'processing' => $statusProcessingCount,
            'completed' => $statusCompletedCount,
            'cancelled' => $statusCancelledCount,
            'total_amount_completed' => $totalAmountComplete,
            'max_amount_order' => $maxAmountOrderDetail[array_key_first($maxAmountOrderDetail)]['order_id'] . "-" . $maxAmountOrder
        ];
    }
    public function getOrderByStatus(array $orders, string $status):array {
        if (trim($status) === 'all') {
            return $orders; // Return all orders if status is empty
        }
        else {
            $results=array_filter($orders, function($order) use ($status) {
                return $order['status'] === $status;
            });
            if (empty($results)) {
                return []; // Return an empty array if no orders match the status
            }
            return $results;
        }
    }
    public function sortAmountASCOrDEC(array $orders, string $sort):array {
        if (trim($sort)==='asc') {
           asort(array_values(array_column($orders, 'amount')));
           array_multisort(array_column($orders, 'amount'), SORT_ASC, $orders);
        }
        elseif (trim($sort)==='desc') {
            arsort(array_values(array_column($orders, 'amount')));
            array_multisort(array_column($orders, 'amount'), SORT_DESC, $orders);
        }
        elseif (trim($sort)==='all') {
            // No sorting needed
            return $orders;
        }   
        return $orders;
    }
    public function getOrderSecondMaxAmountWithoutSort(array $orders):array {
        $amounts = array_column($orders, 'amount');
        $maxAmount= max($amounts);
        $secondMaxAmount = null;
        foreach ($amounts as $amount) {
            if ($amount < $maxAmount) {
                if ($secondMaxAmount === null || $amount > $secondMaxAmount) {
                    $secondMaxAmount = $amount; 
                }
            }
        }
        if ($secondMaxAmount === null) {
            return [];
        }
        $secondMaxOrders = array_filter($orders, function($order) use ($secondMaxAmount) {
            return $order['amount'] === $secondMaxAmount;
        });
        return array_key_first($secondMaxOrders) !== null ? $secondMaxOrders[array_key_first($secondMaxOrders)] : [];
    }
    // Phân loại khách hàng theo tổng giá trị đơn hàng của họ, trả về mảng kết hợp với tên khách hàng và tổng giá trị đơn hàng
    public function getOrderCompleteByCustomer(array $orders, string $customerName):array {
        $results= [];
        $results = array_filter($orders, function($order) use ($customerName) {
            return strcasecmp($order['customer_name'], $customerName) === 0 && $order['status'] === 'completed';
        });
        if (empty($results)) {
            return []; // Return an empty array if no orders match the customer name
        }
        else {
            return $results;
        }
    }
    public function totalAmountByCustomer(array $orders, string $customerName):int {
        $completedOrders = $this->getOrderCompleteByCustomer($orders, $customerName);
        if (empty($completedOrders)) {
            return 0; // Return 0 if no completed orders found for the customer 
        } else {
            return array_sum(array_column($completedOrders, 'amount'));
        }
    }
    public function typeCustomer(array $orders):array {
        $customerNames = array_column($orders, 'customer_name');
        $uniqueCustomerNames = array_unique($customerNames);
        $results = [];
        foreach($uniqueCustomerNames as $customerName) {
            $results[$customerName] = $this->totalAmountByCustomer($orders, $customerName);
        }
        if (empty($results)) {
            return []; // Return an empty array if no customers found
        } else {
            foreach($results as $customerName => $totalAmount) {
                if ($totalAmount===0) {
                    $results[$customerName] = 'No completed orders';
                } elseif ($totalAmount > 0 && $totalAmount < 2000000) {
                    $results[$customerName] = 'New';
                } elseif ($totalAmount >= 2000000 && $totalAmount < 5000000) {
                    $results[$customerName] = 'Regular';
                } else {
                    $results[$customerName] = 'VIP';
                }
            }
        }
        return $results;
    }
    public function index() {
        $error="";
        $validateMessage="";
        $orders=[
            [
                "order_id" => "DH001",
                "customer_name" => "Nguyen Van A",
                "amount" => 1500000,
                "status" => "completed"
            ],
            [
                "order_id" => "DH002",
                "customer_name" => "Tran Van B",
                "amount" => 2500000,
                "status" => "pending"
            ],
            [
                "order_id" => "DH003",
                "customer_name" => "Le Van C",
                "amount" => 3500000,
                "status" => "completed"
            ],
            [
                "order_id" => "DH004",
                "customer_name" => "Pham Van D",
                "amount" => 500000,
                "status" => "cancelled"
            ],
            [
                "order_id" => "DH005",
                "customer_name" => "Hoang Van E",
                "amount" => 2000000,
                "status" => "processing"
            ]
        ];
        $results=[];
        $statuses=$this->status;
        $secondMaxOrder=[];
        $typeCustomers=[];
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['orders'])) {
            $orders = $_POST['orders'] ?? [];
            if (empty($orders)) {
                $error = 'Vui lòng nhập dữ liệu đơn hàng';
            } else {
                $orders=$this->convertStringToArray($orders);
                $validateMessage = $this->validateInputOrders($orders);
                if ($validateMessage === 'Valid input orders') {
                    $orders=$this->parseOrders($orders);
                    $results = $this->getStatisticOrders($orders);
                    $secondMaxOrder = $this->getOrderSecondMaxAmountWithoutSort($orders);
                    $typeCustomers = $this->typeCustomer($orders);
                }
                else {
                    $error = $validateMessage;
                    $orders=[];
                }
            }
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['status']))) {
            $status = $_POST['status']?? '';
            if (!in_array($status, $this->status) && trim($status) !== 'all') {
                $error = 'Trạng thái đơn hàng không hợp lệ';
            } else {
                $orders = $this->getOrderByStatus($orders, $status);
            }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['sort']))) {
            $sort = $_POST['sort']?? '';
            if (!in_array($sort, ['asc', 'desc','all'])) {
                $error = 'Sắp xếp đơn hàng không hợp lệ';
            } else {
                $orders = $this->sortAmountASCOrDEC($orders, $sort);
            }
        }
        require_once 'views/order.php';
    }
}
?>