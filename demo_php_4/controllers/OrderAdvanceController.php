<?php
class OrderAdvanceController {
    private $status=["pending","processing","completed","cancelled"];
    private $orders=[
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
    public function validateInputFilter(string $customerName, string $status, int $minAmount, int $maxAmount):string {
        $message="";
        if (!in_array($status, $this->status) && $status !== 'all') {
            $message.="Trạng thái đơn hàng không hợp lệ.";
        } 
        if ($minAmount < 0 || $maxAmount < 0) {
            $message.="Giá trị lọc đơn hàng phải là số nguyên.";
        } 
        if ($minAmount > $maxAmount) {
            $message.="Giá trị tối thiểu phải nhỏ hơn hoặc bằng giá trị tối đa.";
        }
        if (!preg_match('/^[\p{L}\s]*$/u', trim($customerName))) {
            $message.="Tên khách hàng chỉ được chứa chữ cái và khoảng trắng giữa các từ.";
        }
        if ($message==="") {
            $message="Valid input";
        }
        return $message;

    }
    public function validateOrder(array $orders):string {
        if (empty($orders)) {
            return "Dữ liệu đơn hàng không hợp lệ.";
        }
        foreach ($orders as $order) {
            if (trim($order['order_id']) === '') {
                return "Mã đơn hàng không được để trống.";
            }
            elseif (trim($order['customer_name']) === '') {
                return "Tên khách hàng không được để trống.";
            } else if (!preg_match('/^[\p{L}\s]+$/u', trim($order['customer_name']))) {
                return 'Tên khách hàng chỉ được chứa chữ cái và khoảng trắng giữa các từ';
            } elseif(!ctype_digit(trim($order['amount']))) {
                return "Giá trị đơn hàng phải là số nguyên.";
            } elseif (!$this->validateAmountRange(trim($order['amount']))) {
                return "Giá trị đơn hàng phải lớn hơn 0.";
            } elseif (!in_array(trim($order['status']), $this->status)) {
                return "Trạng thái đơn hàng không hợp lệ.";
            }
        }
        return "Valid input";
    }
    public function addOrder(array $order,array &$orders):string {
        foreach ($order as $newOrder) {
            $orders[] = [
                'order_id' => trim($newOrder['order_id']),
                'customer_name' => trim($newOrder['customer_name']),
                'amount' => (int)trim($newOrder['amount']),
                'status' => trim($newOrder['status'])
            ];
        }
        return "Thêm đơn hàng thành công.";
    }
    public function filterCustomerName(array $orders, string $customerName):array {
        $results=array_filter($orders, function($order) use ($customerName) {
            return stripos($order['customer_name'], $customerName) !== false;
        });
        if (empty($results)) {
            return [];
        }
        return $results;
    }
    public function filterOrders(array $orders, string $status):array {
        if (trim($status) === 'all') {
            return $orders;
        }
        else {
            $results=array_filter($orders, function($order) use ($status) {
                return $order['status'] === $status;
            });
            return $results;
        }
    }
    public function filterOrderAmount(array $orders, int $minAmount, int $maxAmount=PHP_INT_MAX):array {
        $results=array_filter($orders, function($order) use ($minAmount, $maxAmount) {
            return (int)$order['amount'] >= $minAmount && (int)$order['amount'] < $maxAmount;
        });
        
        return $results;
    }
    public function sortOrder(array $orders,string $sort):array {
        if (trim($sort)==='asc') {
            array_multisort(array_column($orders, 'amount'), SORT_ASC, $orders);
        }
        elseif (trim($sort)==='desc') {
            array_multisort(array_column($orders, 'amount'), SORT_DESC, $orders);
        }
        elseif (trim($sort)==='all') {
            return $orders;
        }   
        return $orders;
    }
    public function getOrders():array {
        return $this->orders;
    }
    public function calculateStatistic(array $orders):array {
        $totalOrders = count($orders);
        $totalAmount = array_sum(array_column($orders, 'amount'));
        $completedCount=array_count_values(array_column($orders, 'status'))['completed'] ?? 0;
        $pendingCount=array_count_values(array_column($orders, 'status'))['pending'] ?? 0;
        $processingCount=array_count_values(array_column($orders, 'status'))['processing'] ?? 0;
        $cancelledCount=array_count_values(array_column($orders, 'status'))['cancelled'] ?? 0;
        $revenue=array_sum(array_column(array_filter($orders, function($order) {
            return $order['status'] === 'completed';
        }), 'amount'));
        $percentageStatuses=[
            'completed' => $totalOrders > 0 ? ($completedCount / $totalOrders) * 100 : 0,
            'pending' => $totalOrders > 0 ? ($pendingCount / $totalOrders) * 100 : 0,
            'processing' => $totalOrders > 0 ? ($processingCount / $totalOrders) * 100 : 0,
            'cancelled' => $totalOrders > 0 ? ($cancelledCount / $totalOrders) * 100 : 0
        ];
        return [
            'total_orders' => $totalOrders,
            'total_amount' => $totalAmount,
            'revenue' => $revenue,
            'percentage_statuses' => $percentageStatuses,
            'completed_count' => $completedCount,
            'pending_count' => $pendingCount,
            'processing_count' => $processingCount,
            'cancelled_count' => $cancelledCount
        ];
    }
    public function groupOrdersByCustomer(array $orders):array {
        $groupedOrders = [];
        foreach ($orders as $order) {
            $customerName = $order['customer_name'];
            if (!isset($groupedOrders[$customerName])) {
                $groupedOrders[$customerName] = [];
            }
            $groupedOrders[$customerName][] = $order;
        }
        return $groupedOrders;
    }
    public function calculateCustomerRevenue(array $orders):array {
        $customerRevenue = [];
        foreach ($orders as $order) {
            if ($order['status'] === 'completed') {
                $customerName = $order['customer_name'];
                if (!isset($customerRevenue[$customerName])) {
                    $customerRevenue[$customerName] = 0;
                }
                $customerRevenue[$customerName] += $order['amount'];
            }
        }
        return $customerRevenue;
    }
    public function classifyCustomersByRevenue(array $customerRevenue):array {
        $results = [];
        foreach ($customerRevenue as $customerName => $revenue) {
            if ($revenue < 1000000) {
                $results[$customerName] = 'New';
            } elseif ($revenue >= 1000000 && $revenue <= 5000000) {
                $results[$customerName] = 'Regular';
            } else {
                $results[$customerName] = 'VIP';
            }
        }
        return $results;
    }
    public function getStatisticByCustomer(array $orders):array {
        $groupedOrders = $this->groupOrdersByCustomer($orders);
        // doanh thu khách hàng
        $customerRevenue = $this->calculateCustomerRevenue($orders);
        // phân loại khách hàng
        $customerClassification = $this->classifyCustomersByRevenue($customerRevenue);
        $statisticCustomer = [];
        foreach ($groupedOrders as $customerName => $customerOrders) {
            $statisticCustomer[$customerName] = [
                'total_orders' => count($customerOrders),
                'total_amount' => array_sum(array_column($customerOrders, 'amount')),
                'revenue' => $customerRevenue[$customerName] ?? 0,
                'complete_orders'=> count(array_filter($customerOrders, function($order) {
                    return $order['status'] === 'completed';
                })),
                'cancelled_orders'=> count(array_filter($customerOrders, function($order) {
                    return $order['status'] === 'cancelled';
                })),
                'classification' => $customerClassification[$customerName] ?? 'New',
                'percent_cancelled' => $this->percentCancelledAnalyst($customerOrders)
            ];
        }
        return $statisticCustomer;
    }
    // lấy top 3 khách hàng có doanh thu cao nhất( có thể đồng hạng)
    public function findTopRevenueCustomer(array $customerRevenue, int $topN = 3):array {
        $topRevenueCustomers = [];
        $rank = 0;
        $previousRevenue = null;
        foreach ($customerRevenue as $customerName => $revenue) {
            if ($rank >= $topN && $revenue < $previousRevenue) {
                break;  
            }
            elseif($rank < $topN || $revenue === $previousRevenue) {
                $topRevenueCustomers[$customerName] = $revenue;
            }
            $rank++;
            $previousRevenue = $revenue;
        }
        return $topRevenueCustomers;
    }
    public function findTopAmountOrder(array $orders, int $topN = 3):array {
        $orders=$this->sortOrder($orders, 'desc');
        $topAmountOrders = [];
        $rank = 0;
        $previousAmount = null;
        foreach ($orders as $order) {
            if ($rank >= $topN && $order['amount'] < $previousAmount) {
                break;  
            }
            elseif($rank < $topN || $order['amount'] === $previousAmount) {
                $topAmountOrders[] = $order;
            }
            $rank++;
            $previousAmount = $order['amount'];
        }
        return $topAmountOrders;
    }
    public function AbnormalOrders(array $orders):array {
        $customerCanCelTwoOrders=[];
        $groupedOrders = $this->groupOrdersByCustomer($orders);
        foreach ($groupedOrders as $customerName => $customerOrders) {
            $cancelledOrdersCount = count(array_filter($customerOrders, function($order) {
                return $order['status'] === 'cancelled';
            }));
            if ($cancelledOrdersCount >= 2) {
                $customerCanCelTwoOrders[$customerName] = $cancelledOrdersCount;
            }
        }
        $orderAmountOver=array_filter($orders, function($order) {
            return $order['amount'] > 3000000;
        });
        $orderId=array_count_values(array_column($orders, 'order_id'));
        $duplicateOrderId=array_filter($orderId, function($count) {
            return $count > 1;
        });
        if (!empty($duplicateOrderId)) {
            $duplicateOrderIds = array_keys($duplicateOrderId);
        }
        return [$orderAmountOver, $customerCanCelTwoOrders, $duplicateOrderIds ?? []];
    }
    public function percentCancelledAnalyst(array $orders):float {
        $totalOrders = count($orders);
        $cancelledCount = count(array_filter($orders, function($order) {
            return $order['status'] === 'cancelled';
        }));
        return $totalOrders > 0 ? ($cancelledCount / $totalOrders) * 100 : 0;
    }
    public function findMaxPercentCancelledCustomer(array $statisticCustomer):array {
        $maxPercent = 0;
        $maxPercentCustomers = [];
        foreach ($statisticCustomer as $customer => $data) {
            if ($data['percent_cancelled'] > $maxPercent) {
                $maxPercent = $data['percent_cancelled'];
                $maxPercentCustomers = [$customer];
            } elseif ($data['percent_cancelled'] === $maxPercent) {
                $maxPercentCustomers[] = $customer;
            }
        }
        return ['customers' => $maxPercentCustomers, 'percent' => $maxPercent];
    }

    public function index() {
        $error="";
        $message="";
        $result=[]; 
        $orders = $_SESSION['orders'] ?? $this->getOrders();
        $statistics=$this->calculateStatistic($orders);
        $statisticCustomer=$this->getStatisticByCustomer($orders);
        $maxPercentCancelledCustomer=$this->findMaxPercentCancelledCustomer($statisticCustomer);
        $topRevenueCustomers=$this->findTopRevenueCustomer($this->calculateCustomerRevenue($this->groupOrdersByCustomer($orders)));
        $topAmountOrders=$this->findTopAmountOrder($orders);
        $abnormalOrders=$this->AbnormalOrders($orders);
        $statuses=$this->status;
        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['input'])) {
            $input=$_POST['input'] ?? '';
            $result=$this->convertStringToArray($input);
            $validationResult=$this->validateOrder($result);
            if ($validationResult!=="Valid input") {
                $error=$validationResult;
            }
            else {
                $message=$this->addOrder($result,$orders);
                $_SESSION['orders']=$orders;
                $statistics=$this->calculateStatistic($orders);
                $statisticCustomer=$this->getStatisticByCustomer($orders);
                $topRevenueCustomers=$this->findTopRevenueCustomer($this->calculateCustomerRevenue($this->groupOrdersByCustomer($orders)));
                $topAmountOrders=$this->findTopAmountOrder($orders);
                $abnormalOrders=$this->AbnormalOrders($orders);
                $maxPercentCancelledCustomer=$this->findMaxPercentCancelledCustomer($statisticCustomer);
            }
            
        } 
        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['filter'])) {
            $customerName=$_POST['customer_name'] ?? '';
            $filterStatus=$_POST['status'] ?? 'all';
            $minAmount=$_POST['min_amount'] ?? '';
            $maxAmount=$_POST['max_amount'] ?? '';
            $minAmount=trim($minAmount)==='' ? 0 : $minAmount;
            $maxAmount=trim($maxAmount)==='' ? PHP_INT_MAX : $maxAmount;
            $validateInputFilter=$this->validateInputFilter($customerName,$filterStatus,$minAmount,$maxAmount);
            if ($validateInputFilter!=="Valid input") {
                $error.=$validateInputFilter;
            } else {
                $orders=$this->filterOrders($orders, $filterStatus);
                $orders=$this->filterCustomerName($orders, $customerName);
                $orders=$this->filterOrderAmount($orders, (int)$minAmount, (int)$maxAmount);
            }
        }

        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['sort'])) {
            $sortOrder=$_POST['sort'] ?? 'all';
            $orders = $this->sortOrder($orders, $sortOrder);
        }
        require_once 'views/order_advance.php';
    }
}

?>