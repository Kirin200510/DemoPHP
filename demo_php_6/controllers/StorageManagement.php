<?php
class StorageManagement {
    private $type=[
        'import','export','transfer'
    ];
    private $products=[
        ['product_id'=>'SP001',
        'product_name'=>'Chuột máy tính',
        'unit_price'=>150000,
        'min_stock'=>10
        ],
        ['product_id'=>'SP002',
        'product_name'=>'Bàn phím',
        'unit_price'=>450000,
        'min_stock'=>5
        ],
        ['product_id'=>'SP003',
        'product_name'=>'Màn hình',
        'unit_price'=>2500000,
        'min_stock'=>3
        ],
        ['product_id'=>'SP004',
        'product_name'=>'USB',
        'unit_price'=>120000,
        'min_stock'=>8
        ]
    ];
    private $storages=[
        ['warehouse_id'=>'WH001',
        'warehouse_name'=>'Kho Hồ Chí Minh',
        ],
        ['warehouse_id'=>'WH002',
        'warehouse_name'=>'Kho Hà Nội',
        ]
    ];
    private $storage_quantity=[
        ['warehouse_id'=>'WH001',
        'product_id'=>'SP001',
        'quantity'=>20,
        'current_quantity'=>20
        ],
        ['warehouse_id'=>'WH001',
        'product_id'=>'SP002',
        'quantity'=>10,
        'current_quantity'=>10
        ],
        ['warehouse_id'=>'WH001',
        'product_id'=>'SP003',
        'quantity'=>5,
        'current_quantity'=>5
        ],
        ['warehouse_id'=>'WH001',
        'product_id'=>'SP004',
        'quantity'=>15,
        'current_quantity'=>15
        ],
        ['warehouse_id'=>'WH002',
        'product_id'=>'SP001',
        'quantity'=>10,
        'current_quantity'=>10
        ],
        ['warehouse_id'=>'WH002',
        'product_id'=>'SP002',
        'quantity'=>20,
        'current_quantity'=>20
        ],
        ['warehouse_id'=>'WH002',
        'product_id'=>'SP003',
        'quantity'=>10,
        'current_quantity'=>10
        ],
        ['warehouse_id'=>'WH002',
        'product_id'=>'SP004',
        'quantity'=>25,
        'current_quantity'=>25
        ]
    ];
    private $transactions=[];
    public function getProducts():array {
        return $this->products;
    }
    public function getStorages():array {
        return $this->storages;
    }
    // Tính tổng tồn kho theo kho
    public function calcutelateWarehouseStock(array $storage_quantity,array $storages):array {
        $results=[];
        foreach($storages as $storage){
            $warehouse_id=$storage['warehouse_id'];
            $total_quantity=0;
            foreach($storage_quantity as $sq){
                if($sq['warehouse_id']==$warehouse_id){
                    $total_quantity+=$sq['current_quantity'];
                }
            }
            $results[]=[
                'warehouse_id'=>$warehouse_id,
                'total_quantity'=>$total_quantity
            ];
            
        }
        return $results;
    }

    //Tính tổng tồn kho theo sản phẩm 
    public function calcutelateProductStock(array $storage_quantity,array $products):array {
        $results=[];
        foreach($products as $product){
            $product_id=$product['product_id'];
            $total_quantity=0;
            foreach($storage_quantity as $sq){
                if($sq['product_id']===$product_id){
                    $total_quantity+=$sq['current_quantity'];
                }
            }
            $results[]=[
                'product_id'=>$product_id,
                'total_quantity'=>$total_quantity
            ];
        }
        return $results;
    }
    public function getCurrentQuantity(string $warehouse_id,string $product_id,array $storage_quantity):int {
        foreach($storage_quantity as $sq){
            if($sq['warehouse_id']===$warehouse_id && $sq['product_id']===$product_id){
                return $sq['current_quantity'];
            }
        }
        return 0;
    }
    public function getQuantityManagement(array $storage_quantity,array $products):array {
        $results=[];
        foreach($products as $product) {
            $results[]=[
                'product_id'=>$product['product_id'],
                'storage_quantity'=>[
                    'WH001'=>$this->getCurrentQuantity('WH001',$product['product_id'],$storage_quantity),
                    'WH002'=>$this->getCurrentQuantity('WH002',$product['product_id'],$storage_quantity)
                ],
                
                'total_quantity'=>$this->calcutelateProductStock($storage_quantity,[$product])[0]['total_quantity']
            ];
        }
        return $results;
    }
    public function convertStringToArray(string $input,string $type):array {
        $lines=explode("\n",$input);
        $transactions=[];
        foreach($lines as $line){
            $line=trim($line);
            if(!empty($line)){
                $fields=explode(",",$line);
                if (count($fields)===4) {
                    $transactions[]=[
                        'transaction_id'=>trim($fields[0]),
                        'product_id'=>trim($fields[1]),
                        'type'=>$type,
                        'quantity'=>trim($fields[3]),
                        'source_warehouse'=>trim($fields[2]),
                        'target_warehouse'=>null
                    ];
                } elseif (count($fields)===5) {
                    $transactions[]=[
                        'transaction_id'=>trim($fields[0]),
                        'product_id'=>trim($fields[1]),
                        'type'=>$type,
                        'quantity'=>trim($fields[4]),
                        'source_warehouse'=>trim($fields[2]),
                        'target_warehouse'=>trim($fields[3])
                    ];
                }
            }
        }
        return $transactions;
    }
    public function validateQuantityRange(string $quantity):bool {
        $quantity=ltrim($quantity,'0');
        $validateQuantity=filter_var($quantity,FILTER_VALIDATE_INT,[
            'options'=>[
                'min_range'=>1
            ]
        ]);
        return $validateQuantity !== false;
    }
    public function validateTransaction(array $transactions):string {
        if (empty($transactions)) {
            return "Transaction data is empty.";
        } 
        foreach($transactions as $transaction) {
            if ($transaction['transaction_id']==='') {
                return "Mã giao dịch rỗng";
            } elseif(in_array($transaction['transaction_id'], array_column($_SESSION['transactions']??[], 'transaction_id'))) {
                return "Mã giao dịch bị trùng";
            } elseif(!in_array($transaction['product_id'], array_column($this->products, 'product_id'))) {
                return "Mã sản phẩm không tồn tại";
            } elseif(!in_array($transaction['source_warehouse'], array_column($this->storages, 'warehouse_id'))) {
                return "Mã kho nguồn không tồn tại";
            } elseif(!in_array($transaction['type'], $this->type)) {
                return "Loại giao dịch không hợp lệ";
            } elseif($transaction['type']==='transfer' && !in_array($transaction['target_warehouse'], array_column($this->storages, 'warehouse_id'))) {
                return "Mã kho đích không tồn tại";
            } elseif(!ctype_digit($transaction['quantity'])) {
                return "Số lượng phải là số nguyên";
            } elseif(!$this->validateQuantityRange($transaction['quantity'])) {
                return "Số lượng không được <= 0";
            } elseif ($transaction['type']==='export') {
                if ($transaction['quantity'] > $this->getCurrentQuantity($transaction['source_warehouse'], $transaction['product_id'],$_SESSION['storage_quantity'] ?? $this->storage_quantity)) {
                    return "Số lượng xuất vượt quá tồn kho hiện tại của kho xuất";
                }
            } elseif ($transaction['type']==='transfer') {
                if ($transaction['quantity'] > $this->getCurrentQuantity($transaction['source_warehouse'], $transaction['product_id'],$_SESSION['storage_quantity'] ?? $this->storage_quantity)) {
                    return "Số lượng chuyển vượt quá tồn kho hiện tại của kho nguồn";
                }
                elseif($transaction['source_warehouse'] === $transaction['target_warehouse']) {
                    return "Kho nguồn và kho đích không được trùng nhau";
                }
            } 
        }
        return "Valid transaction";
    }
    public function importStock(array &$storage_quantity, array &$transactions,array $newTransactions):string {
        foreach($newTransactions as $transaction) {
            $storage_quantity_import=array_filter($storage_quantity, function($sq) use ($transaction) {
                return $sq['warehouse_id'] === $transaction['source_warehouse'] && $sq['product_id'] === $transaction['product_id'];
            });
            if (!empty($storage_quantity_import)) {
                $storage_quantity_import=array_values($storage_quantity_import)[0];
                $storage_quantity_import['current_quantity'] += $transaction['quantity'];
                foreach($storage_quantity as &$sq) {
                    if ($sq['warehouse_id'] === $transaction['source_warehouse'] && $sq['product_id'] === $transaction['product_id']) {
                        $sq=$storage_quantity_import;
                        break;
                    }
                }
                $transactions[]=[
                    'transaction_id'=>$transaction['transaction_id'],
                    'product_id'=>$transaction['product_id'],
                    'type'=>$transaction['type'],
                    'quantity'=>$transaction['quantity'],
                    'source_warehouse'=>$transaction['source_warehouse'],
                    'target_warehouse'=>null
                ];
                return "Giao dịch nhập kho thành công";
            }
        }
    }

    public function exportStock(array &$storage_quantity, array &$transactions,array $newTransactions):string {
        foreach($newTransactions as $transaction) {
            $storage_quantity_export=array_filter($storage_quantity, function($sq) use ($transaction) {
                return $sq['warehouse_id'] === $transaction['source_warehouse'] && $sq['product_id'] === $transaction['product_id'];
            });
            if (!empty($storage_quantity_export)) {
                $storage_quantity_export=array_values($storage_quantity_export)[0];
                if ($transaction['quantity'] <= $storage_quantity_export['current_quantity']) {
                    $storage_quantity_export['current_quantity'] -= $transaction['quantity'];
                    foreach($storage_quantity as &$sq) {
                        if ($sq['warehouse_id'] === $transaction['source_warehouse'] && $sq['product_id'] === $transaction['product_id']) {
                            $sq=$storage_quantity_export;
                            break;
                        }
                    }
                    $transactions[]=[
                        'transaction_id'=>$transaction['transaction_id'],
                        'product_id'=>$transaction['product_id'],
                        'type'=>$transaction['type'],
                        'quantity'=>$transaction['quantity'],
                        'source_warehouse'=>$transaction['source_warehouse'],
                        'target_warehouse'=>null
                    ];
                    return "Giao dịch xuất kho thành công";
                }
            } 
        }
    }

    public function transferStock(array &$storage_quantity, array &$transactions,array $newTransactions):string {
        foreach($newTransactions as $transaction) {
            $storage_quantity_source=array_filter($storage_quantity, function($sq) use ($transaction) {
                return $sq['warehouse_id'] === $transaction['source_warehouse'] && $sq['product_id'] === $transaction['product_id'];
            });
            $storage_quantity_target=array_filter($storage_quantity, function($sq) use ($transaction) {
                return $sq['warehouse_id'] === $transaction['target_warehouse'] && $sq['product_id'] === $transaction['product_id'];
            });
            if (!empty($storage_quantity_source) && !empty($storage_quantity_target)) {
                $storage_quantity_source=array_values($storage_quantity_source)[0];
                $storage_quantity_target=array_values($storage_quantity_target)[0];
                if ($transaction['quantity'] <= $storage_quantity_source['current_quantity']) {
                    $storage_quantity_source['current_quantity'] -= $transaction['quantity'];
                    $storage_quantity_target['current_quantity'] += $transaction['quantity'];
                    foreach($storage_quantity as &$sq) {
                        if ($sq['warehouse_id'] === $transaction['source_warehouse'] && $sq['product_id'] === $transaction['product_id']) {
                            $sq=$storage_quantity_source;
                        } elseif ($sq['warehouse_id'] === $transaction['target_warehouse'] && $sq['product_id'] === $transaction['product_id']) {
                            $sq=$storage_quantity_target;
                        }
                    }
                    $transactions[]=[
                        'transaction_id'=>$transaction['transaction_id'],
                        'product_id'=>$transaction['product_id'],
                        'type'=>$transaction['type'],
                        'quantity'=>$transaction['quantity'],
                        'source_warehouse'=>$transaction['source_warehouse'],
                        'target_warehouse'=>$transaction['target_warehouse']
                    ];
                    return "Giao dịch chuyển kho thành công";
                }
            } 
        }
    }
    public function getLowStockProducts(array $products, array $storage_quantity):array {
        $currentQuantityByProduct=$this->calcutelateProductStock($storage_quantity,$products);
        $lowStockProducts=[];
        foreach($currentQuantityByProduct as $productStock){
            $productId=$productStock['product_id'];
            $currentQuantity=$productStock['total_quantity'];
            $product=array_filter($products,function($p) use ($productId){
                return $p['product_id']===$productId;
            });
            if(!empty($product)){
                $product=array_values($product)[0];
                if($currentQuantity<=$product['min_stock']){
                    $lowStockProducts[]=[
                        'product_id'=>$productId,
                        'product_name'=>$product['product_name'],
                        'total_current_quantity'=>$currentQuantity,
                        'min_stock'=>$product['min_stock']
                    ];
                }
            }
        }
        return $lowStockProducts;
    }
    public function getInventoryValueByStorage(array $storage_quantity, array $products,array $storages):array {
        $results=[];
        foreach($storages as $storage){
            $warehouse_id=$storage['warehouse_id'];
            foreach($storage_quantity as $sq){
                if($sq['warehouse_id']===$warehouse_id){
                    $product=array_filter($products,function($p) use ($sq){
                        return $p['product_id']===$sq['product_id'];
                    });
                    if(!empty($product)){
                        $product=array_values($product)[0];
                        $total_value=$sq['current_quantity']*$product['unit_price'];
                        $results[$warehouse_id][]=[
                            'product_id'=>$sq['product_id'],
                            'current_quantity'=>$sq['current_quantity'],
                            'unit_price'=>$product['unit_price'],
                            'total_value'=>$total_value
                        ];
                    }
                }
            }
        }
        return $results;
    }
    public function getInventoryStatistics(array $products, array $storage_quantity,array $storages,array $transactions):array{
        $total_products=count($products);
        $total_warehouses=count($storages);
        $quantityByStorage=$this->calcutelateWarehouseStock($storage_quantity,$storages);
        $total_quantity_count= array_sum(array_column($quantityByStorage,'total_quantity'));
        $inventoryValue=$this->getInventoryValueByStorage($storage_quantity,$products,$storages);
        $total_quantity_value_storage=[];
        foreach($inventoryValue as $warehouse_id=>$values){
            $total_quantity_value_storage[$warehouse_id]=array_sum(array_column($values,'total_value'));
        }
        $total_quantity_value=array_sum(array_values($total_quantity_value_storage));
        $totalImportQuantity=array_sum(array_map(function($t){
            return $t['type']==='import' ? $t['quantity'] : 0;
        },$transactions));
        $totalExportQuantity=array_sum(array_map(function($t){
            return $t['type']==='export' ? $t['quantity'] : 0;
        },$transactions));
        $totalTransferQuantity=array_sum(array_map(function($t){
            return $t['type']==='transfer' ? $t['quantity'] : 0;
        },$transactions));
        $lowStockProductCount=count($this->getLowStockProducts($products,$storage_quantity));
        return [
            'total_products'=>$total_products,
            'total_warehouses'=>$total_warehouses,
            'total_quantity_count'=>$total_quantity_count,
            'total_quantity_value'=>$total_quantity_value,
            'total_import_quantity'=>$totalImportQuantity,
            'total_export_quantity'=>$totalExportQuantity,
            'total_transfer_quantity'=>$totalTransferQuantity,
            'low_stock_product_count'=>$lowStockProductCount,
            'total_quantity_value_storage'=>$total_quantity_value_storage
        ];
    }

    public function getTransactionsHistory(array $transactions):array {
        $histories=[];
        foreach($transactions as $transaction) {
            $histories[]=[
                'transaction_id'=>$transaction['transaction_id'],
                'product_id'=>$transaction['product_id'],
                'type'=>$transaction['type'],
                'quantity'=>$transaction['quantity'],
                'source_warehouse'=>$transaction['source_warehouse'],
                'target_warehouse'=>$transaction['target_warehouse'] ?? '-',
            ];
        }
        return $histories;
    }
    public function filterTransactions(array $transactions, string $searchTerm,string $typeSearch):array {
        $filteredTransactions=[];
        foreach($transactions as $transaction) {
            if ($typeSearch=='warehouse') {
                if(stripos($transaction['source_warehouse'], $searchTerm) !== false || stripos($transaction['target_warehouse'] ?? '', $searchTerm) !== false) {
                    $filteredTransactions[]=$transaction;
                }
            } else {
                if (stripos($transaction[$typeSearch], $searchTerm) !== false) {
                    $filteredTransactions[]=$transaction;
                }
            }
        }
        return $filteredTransactions;
    }
    public function filterTransactionsByType(array $transactions, string $type):array {
        if ($type==='all') {
            return $transactions;
        } else {
            return array_filter($transactions, function($transaction) use ($type) {
                return $transaction['type']===$type;
            });
        }
    }

    public function index() {
        $error="";
        $message="";
        $transactions=$_SESSION['transactions'] ?? $this->transactions;
        $products=$this->getProducts();
        $storages=$this->getStorages();
        $storage_quantity=$_SESSION['storage_quantity'] ?? $this->storage_quantity;
        $quantityManagement=$this->getQuantityManagement($storage_quantity,$products);
        $calculateWareHouseStock=$this->calcutelateWarehouseStock($storage_quantity,$storages);
        $inventoryValue=$this->getInventoryValueByStorage($storage_quantity,$products,$storages);
        $inventoryStatistics=$this->getInventoryStatistics($products,$storage_quantity,$storages,$transactions);
        $lowStockProducts=$this->getLowStockProducts($products,$storage_quantity);
        $transactionsHistory=$this->getTransactionsHistory($transactions);
        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['process'])){
            $type=$_POST['type'];
            $input=$_POST['input'] ?? '';
            $newTransactions=$this->convertStringToArray($input,$type);
            $validationResult=$this->validateTransaction($newTransactions);
            if($validationResult==="Valid transaction"){
                if($type==='import'){
                    $message=$this->importStock($storage_quantity,$transactions,$newTransactions);
                }elseif($type==='export'){
                    $message=$this->exportStock($storage_quantity,$transactions,$newTransactions);
                }elseif($type==='transfer'){
                    $message=$this->transferStock($storage_quantity,$transactions,$newTransactions);
                }
                $_SESSION['transactions']=$transactions;
                $_SESSION['storage_quantity']=$storage_quantity;
                $quantityManagement=$this->getQuantityManagement($storage_quantity,$products);
                $calculateWareHouseStock=$this->calcutelateWarehouseStock($storage_quantity,$storages);
                $inventoryValue=$this->getInventoryValueByStorage($storage_quantity,$products,$storages);
                $inventoryStatistics=$this->getInventoryStatistics($products,$storage_quantity,$storages,$transactions);
                $lowStockProducts=$this->getLowStockProducts($products,$storage_quantity);
                $transactionsHistory=$this->getTransactionsHistory($transactions);
            }else{
                $error=$validationResult;
            }
        }
        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['filter'])) {
            $productId=trim($_POST['product_id'] ?? '');
            $warehouse_id=trim($_POST['warehouse_id'] ?? '');
            $transaction_id=trim($_POST['transaction_id'] ?? '');
            $type=trim($_POST['type'] ?? 'all');
            $filteredTransactions=$_SESSION['transactions'] ?? $this->transactions;
            if ($transaction_id !== '') {
                $filteredTransactions=$this->filterTransactions($filteredTransactions,$transaction_id,'transaction_id');
            }
            if ($productId !== '') {
                $filteredTransactions=$this->filterTransactions($filteredTransactions,$productId,'product_id');
            }
            if ($warehouse_id !== '') {
                $filteredTransactions=$this->filterTransactions($filteredTransactions,$warehouse_id,'warehouse');
            }
            $filteredTransactions=$this->filterTransactionsByType($filteredTransactions,$type);
            $transactionsHistory=$filteredTransactions;
        }
        require_once 'views/storage_management.php';
    }


}
?>