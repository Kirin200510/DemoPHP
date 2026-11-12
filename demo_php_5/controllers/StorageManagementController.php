<?php
class StorageManagementController {
    private $type=['import', 'export'];
    private $typeStock=['In Stock', 'Low Stock'];
    private $products=[
        [
            'product_id'=>'SP001',
            'product_name'=>'Chuột máy tính',
            'unit_price'=>150000,
            'quantity'=>20,
            'current_quantity'=>20,
            'min_stock'=>5
        ],
        [
            'product_id'=>'SP002',
            'product_name'=>'Bàn phím',
            'unit_price'=>450000,
            'quantity'=>15,
            'current_quantity'=>15,
            'min_stock'=>3
        ],
        [
            'product_id'=>'SP003',
            'product_name'=>'Màn hình',
            'unit_price'=>2500000,
            'quantity'=>8,
            'current_quantity'=>8,
            'min_stock'=>2
        ],
        [
            'product_id'=>'SP004',
            'product_name'=>'USB',
            'unit_price'=>120000,
            'quantity'=>30,
            'current_quantity'=>30,
            'min_stock'=>10
        ],
        [
            'product_id'=>'SP005',
            'product_name'=>'Tai nghe',
            'unit_price'=>650000,
            'quantity'=>10,
            'current_quantity'=>10,
            'min_stock'=>2
        ]
    ];
    private $transactions=[
        // [
        //     'transaction_id'=>'PN001',
        //     'product_id'=>'SP001',
        //     'type'=>'import',
        //     'quantity'=>10
        // ],
        // [
        //     'transaction_id'=>'PX001',
        //     'product_id'=>'SP001',
        //     'type'=>'export',
        //     'quantity'=>5
        // ],
        // [
        //     'transaction_id'=>'PX002',
        //     'product_id'=>'SP002',
        //     'type'=>'export',
        //     'quantity'=>4
        // ],
        // [
        //     'transaction_id'=>'PN002',
        //     'product_id'=>'SP003',
        //     'type'=>'import',
        //     'quantity'=>2,
        // ],
        // [
        //     'transaction_id'=>'PX003',
        //     'product_id'=>'SP003',
        //     'type'=>'export',
        //     'quantity'=>3
        // ]
    ];
    public function updateStatusStock(array $product): array {
        if ($product['current_quantity'] <= $product['min_stock']) {
            $product['stock_status'] = $this->typeStock[1]; // Low Stock
        } else {
            $product['stock_status'] = $this->typeStock[0]; // In Stock
        }
        return $product;
    }
    // Hiển thị danh sách sản phẩm và trạng thái tồn kho
    public function getProducts() {
        $prods=$this->products;
        foreach($prods as &$product) {
            $product = $this->updateStatusStock($product);
        }
        return $prods;
    }
    public function getTransactions() {
        return $this->transactions;
    }
    public function convertStringToArray(string $input,string $type):array {
        $transactions=[];
        $lines=explode("\n", $input);
        foreach($lines as $line) {
            $line=trim($line);
            if (!empty($line)) {
                $fields=explode(',', $line);
                if (count($fields) === 3) {
                    $transactions[]=[
                        'transaction_id'=>trim($fields[0]),
                        'product_id'=>trim($fields[1]),
                        'type'=>$type,
                        'quantity'=>trim($fields[2])
                    ];
                }
                else {
                    return [];
                }
            }
        }
        return $transactions;
    }
    public function validateQuantityRange(string $range):bool {
        $quantity=ltrim($range,'0');
        $validateQuantity=filter_var($quantity, FILTER_VALIDATE_INT, [
            'options'=>[
                'min_range'=>1
            ]
        ]);
        return $validateQuantity !== false;
    }
    public function validateTransaction(array $transactions):string {
        if (empty($transactions)) {
            return "Transaction data is empty";
        }
        foreach($transactions as $transaction) {
            if ($transaction['transaction_id'] === '' ) {
                return "Mã giao dịch rỗng";
            } // đk 
            elseif(in_array($transaction['transaction_id'], array_column($_SESSION['transactions'], 'transaction_id'))) {
                return "Mã giao dịch đã tồn tại";
            }  elseif (!in_array($transaction['type'], $this->type)) {
                return "Loại giao dịch không tồn tại";
            } elseif (!in_array($transaction['product_id'], array_column($this->products, 'product_id'))) {
                return "Mã sản phẩm không tồn tại";
            } elseif (!ctype_digit($transaction['quantity'])) {
                return "Số lượng không phải là số nguyên";
            } elseif (!$this->validateQuantityRange($transaction['quantity'])) {
                return "Số lượng phải là số nguyên dương";
            }
            elseif ($transaction['type'] === 'export') {
                $product = array_filter($this->products, function($p) use ($transaction) {
                    return $p['product_id'] === $transaction['product_id'];
                });
                if (!empty($product)) {
                    $product = array_values($product)[0];
                    if ($transaction['quantity'] > $product['current_quantity']) {
                        return "Số lượng xuất vượt quá số lượng tồn kho hiện tại";
                    }
                }
            }
        }
        return "Valid transaction";
    }
    public function addTransactionImport(array &$products,array &$transactions, array $newTransactions):string {
        foreach($newTransactions as $transaction) {
            $product = array_filter($products, function($p) use ($transaction) {
                return $p['product_id'] === $transaction['product_id'];
            });
            if (!empty($product)) {
                $product = array_values($product)[0];
                if ($transaction['type'] === 'import') {
                    $product['current_quantity'] += (int)$transaction['quantity'];
                    $product = $this->updateStatusStock($product);
                    foreach($products as &$p) {
                        if ($p['product_id'] === $product['product_id']) {
                            $p = $product;
                            break;
                        }
                    }
                    $transactions[]=[
                        'transaction_id'=>trim($transaction['transaction_id']),
                        'product_id'=>trim($transaction['product_id']),
                        'type'=>'import',
                        'quantity'=>(int)trim($transaction['quantity'])
                    ];
                    return "Giao dịch nhập kho đã được thêm thành công";
                } else {
                    return "Loại giao dịch không hợp lệ";
                }
            } else {
                return "Mã sản phẩm không tồn tại";
            }
        }
    }
       
    public function addTransactionExport(array &$products,array &$transactions, array $newTransactions):string {
        foreach($newTransactions as $transaction) {
            $product = array_filter($products, function($p) use ($transaction) {
                return $p['product_id'] === $transaction['product_id'];
            });
            if (!empty($product)) {
                $product = array_values($product)[0];
                if ($transaction['type'] === 'export' && $transaction['quantity'] <= $product['current_quantity']) {
                    $product['current_quantity'] -= (int)$transaction['quantity'];
                    $product = $this->updateStatusStock($product);
                    foreach($products as &$p) {
                        if ($p['product_id'] === $product['product_id']) {
                            $p = $product;
                            break;
                        }
                    }
                    $transactions[]=[
                        'transaction_id'=>trim($transaction['transaction_id']),
                        'product_id'=>trim($transaction['product_id']),
                        'type'=>'export',
                        'quantity'=>(int)trim($transaction['quantity'])
                    ];
                    return "Giao dịch xuất kho đã được thêm thành công";
                } else {
                    return "Số lượng xuất vượt quá số lượng tồn kho ";
                }
            } else {
                return "Mã sản phẩm không tồn tại";
            }
        }
    }
    public function calculateStockByProducts(array $products, array $transactions): array {
        $stockByProducts = [];
        foreach ($products as $product) {
            $totalProductImport = array_sum(array_map(function($transaction) use ($product) {
                return $transaction['product_id'] === $product['product_id'] && $transaction['type'] === 'import' ? $transaction['quantity'] : 0;
            }, $transactions));
            $totalProductExport = array_sum(array_map(function($transaction) use ($product) {
                return $transaction['product_id'] === $product['product_id'] && $transaction['type'] === 'export' ? $transaction['quantity'] : 0;
            }, $transactions));
            $currentStock = $product['current_quantity'];
            $stockByProducts[] = [
                'product_id' => $product['product_id'],
                'product_name' => $product['product_name'],
                'quantity'=> $product['quantity'],
                'current_stock' => $currentStock,
                'total_import' => $totalProductImport,
                'total_export' => $totalProductExport,
                'unit_price' => $product['unit_price'],
                'value_stock' => $currentStock * $product['unit_price']
            ];
        }
        return $stockByProducts;
    }

    public function getStockStatistics(array $products, array $transactions): array {
        $total_products= count(array_unique(array_column($products, 'product_id')));
        $total_import=array_sum(array_map(function($transaction) {
            return $transaction['type'] === 'import' ? $transaction['quantity'] : 0;
        }, $transactions));
        $total_export=array_sum(array_map(function($transaction) {
            return $transaction['type'] === 'export' ? $transaction['quantity'] : 0;
        }, $transactions));
        $product_lowstock_count=count(array_filter($products, function($product) {
            return $this->updateStatusStock($product)['stock_status'] === $this->typeStock[1];
        }));
        $stockByProducts=$this->calculateStockByProducts($products, $transactions);
        $total_present_storage=0;
        $total_current_stock=0;
        foreach ($stockByProducts as $productStock) {
            $total_present_storage += $productStock['value_stock'];
            $total_current_stock+=$productStock['current_stock'];
        }
        return [
            'total_products' => $total_products,
            'total_import' => $total_import,
            'total_export' => $total_export,
            'total_present_storage' => $total_present_storage,
            'low_stock_products' => $product_lowstock_count,
            'total_current_stock' => $total_current_stock
        ];
    }
    public function getLowStockProducts(array $products,array $transactions): array {
        $lowStockProducts = [];
        foreach ($products as $product) {
            if ($this->updateStatusStock($product)['stock_status'] === $this->typeStock[1]) {
                $lowStockProducts[] = [
                    'product_id' => $product['product_id'],
                    'product_name' => $product['product_name'],
                    'current_quantity' => $product['current_quantity'],
                    'min_stock' => $product['min_stock'],
                    'resupply'=> $product['min_stock'] - $this->calculateStockByProducts([$product], $transactions)[0]['current_stock']
                ];
            }
        }
        return $lowStockProducts;
    }
    //Lọc sp theo tên, id
    public function filterProductsByIdAndName(array $products, string $searchTerm): array {
        $filteredProducts = [];
        foreach ($products as $product) {
            if (stripos($product['product_id'], $searchTerm) !== false ||
                stripos($product['product_name'], $searchTerm) !== false ){
                $filteredProducts[] = $product;
            }
        }
        return $filteredProducts;
    }
    public function filterProductsByStatus(array $products, string $status): array {
        $filteredProducts = [];
        if ($status==='all') {
            return $products;
        }
        else {
            foreach ($products as $product) {
                if ($this->updateStatusStock($product)['stock_status'] === $status) {
                    $filteredProducts[] = $product;
                }
            }
            return $filteredProducts;
        }
    }
    public function sortProducts(array $products,array $transactions,string $sort, string $typeSort): array {
        if ($typeSort==='quantity') {
            if ($sort==='asc') {
                array_multisort(array_column($products, 'current_quantity'), SORT_ASC, $products);
            } elseif ($sort==='desc') {
                array_multisort(array_column($products, 'current_quantity'), SORT_DESC, $products);
            } elseif ($sort==='all') {
                return $products;
            }
            return $products;
        } elseif ($typeSort==='value_stock') {
            $stockByProducts=$this->calculateStockByProducts($products, $transactions);
            if ($sort==='asc') {
                array_multisort(array_column($stockByProducts, 'value_stock'), SORT_ASC, $products);
            } elseif ($sort==='desc') {
                array_multisort(array_column($stockByProducts, 'value_stock'), SORT_DESC, $products);
            } elseif ($sort==='all') {
                return $products;
            }
            return $products;
        }
    }

    public function getCheckTransactionHistory(array $transactions): array {
        $history = [];
        $transactionImportCount=count(array_filter($transactions, function($t) {
            return $t['type'] === 'import';
        }));
        $transactionExportCount=count(array_filter($transactions, function($t) {
            return $t['type'] === 'export';
        }));
        $totalImport=$this->getStockStatistics($this->products, $transactions)['total_import'];
        $totalExport=$this->getStockStatistics($this->products, $transactions)['total_export'];
        $history = [
            'total_import_transactions' => $transactionImportCount,
            'total_export_transactions' => $transactionExportCount,
            'total_import_quantity' => $totalImport,
            'total_export_quantity' => $totalExport
        ];
        return $history;
    }
    public function getTransactionHistory(array $transactions, array $products): array {
        $history = [];
        foreach ($transactions as $transaction) {
            if ($transaction['type'] === 'import') {
                $result = 'Nhập thành công';
            } elseif ($transaction['type'] === 'export') {
                $result = 'Xuất thành công';
            } else {    
                $result = 'Loại giao dịch không hợp lệ';
            }
            $history[] = [
                'transaction_id' => $transaction['transaction_id'],
                'product_id' => $transaction['product_id'],
                'product_name' => $products[array_search($transaction['product_id'], array_column($products, 'product_id'))]['product_name'] ?? '',
                'type' => $transaction['type'],
                'quantity' => $transaction['quantity'],
                'result'=> $result
            ];
        }
        return $history;
    }
    public function filterTransaction(array $transactions, string $searchTerm): array {
        $filteredTransactions = [];
        foreach ($transactions as $transaction) {
            if (stripos($transaction['product_id'], $searchTerm) !== false ||
                stripos($transaction['type'], $searchTerm) !== false) {
                $filteredTransactions[] = $transaction;
            }
        }
        return $filteredTransactions;
    }
    public function findMaxProductExport(array $transactions, array $products): array {
        $stockProduct=$this->calculateStockByProducts($products, $transactions);
        $maxExportCount=max(array_map(function($product) {
            return $product['total_export'];
        }, $stockProduct));
        $maxExportProducts=[];
        $maxExportProducts=array_filter($stockProduct, function($product) use ($maxExportCount) {
            return $product['total_export'] === $maxExportCount;
        });
        if ($maxExportCount===0) {
            return [];
        }
        return [
            'max_export_products' => $maxExportProducts,
            'max_export_count' => $maxExportCount,
        ];
    }
    public function index() {
        $error="";
        $message="";
        $products=$_SESSION['products'] ?? $this->getProducts();
        $transactions=$_SESSION['transactions'] ?? $this->getTransactions();
        $calculateStock=$this->calculateStockByProducts($products, $transactions);
        $stockStatistics=$this->getStockStatistics($products, $transactions);
        $lowStockProducts=$this->getLowStockProducts($products, $transactions);
        // kq kiểm tra giao dịch
        $transactionHistory=$this->getCheckTransactionHistory($transactions);
        //ds lịch sử giao dịch
        $transactionHistoryList=$this->getTransactionHistory($transactions, $products);
        $maxExportProducts=$this->findMaxProductExport($transactions, $products);
        // Add transaction import, export
        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['process'])) {
            $input=$_POST['input'] ?? '';
            $type=$_POST['type'];
            $newTransactions=$this->convertStringToArray($input, $type);
            $validationResult=$this->validateTransaction($newTransactions);
            if ($validationResult !== "Valid transaction") {
                $error=$validationResult;
            } else {
                if ($type === 'import') {
                    $message=$this->addTransactionImport($products, $transactions, $newTransactions);
                } elseif ($type === 'export') {
                    $message=$this->addTransactionExport($products, $transactions, $newTransactions);
                }
                $_SESSION['products']=$products;
                $_SESSION['transactions']=$transactions;
                $calculateStock=$this->calculateStockByProducts($products, $transactions);
                $stockStatistics=$this->getStockStatistics($products, $transactions);
                $lowStockProducts= $this->getLowStockProducts($products, $transactions);
                $transactionHistoryList= $this->getTransactionHistory($transactions, $products);
                $transactionHistory= $this->getCheckTransactionHistory($transactions);
                $maxExportProducts=$this->findMaxProductExport($transactions, $products); 
            }
        }
        // Filter and sort product
        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['filter'])) {
            $productId=$_POST['product_id'] ?? '';
            $productName=$_POST['product_name'] ?? '';
            $status=$_POST['status'] ?? 'all';
            $sort=$_POST['sort'] ?? 'all';
            $typeSort=$_POST['type_sort'] ?? 'quantity';
            $products=$this->filterProductsByIdAndName($products, $productId);
            $products=$this->filterProductsByIdAndName($products, $productName);
            $products=$this->filterProductsByStatus($products, $status);
            $products=$this->sortProducts($products, $transactions, $sort, $typeSort);
        } 
        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['filter_transaction'])) {
            $productIdTransaction=$_POST['product_id_transaction'] ?? '';
            $type=$_POST['type_transaction'] ?? 'import';
            $transactionHistoryList=$this->filterTransaction($transactionHistoryList, $productIdTransaction);
            $transactionHistoryList=$this->filterTransaction($transactionHistoryList, $type);

        }
        require_once 'views/storage_management.php';
    }
}
?>