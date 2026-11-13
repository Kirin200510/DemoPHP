import re,sys
from flask import render_template,request

class StorageManagement:
    typeP=['import','export','transfer']
    products=[{
        'product_id':'SP001',
        'product_name':'Chuột máy tính',
        'unit_price':150000,
        'min_stock':10
    },
    {
        'product_id':'SP002',
        'product_name':'Bàn phím',
        'unit_price':450000,
        'min_stock':5
    },
    {
        'product_id':'SP003',
        'product_name':'Màn hình',
        'unit_price':2500000,
        'min_stock':3
    },
    
    {
        'product_id':'SP004',
        'product_name':'USB',
        'unit_price':120000,
        'min_stock':8
    }]
    storages=[{
        'warehouse_id':'WH001',
        'warehouse_name':'Kho HCM',
    },{
        'warehouse_id':'WH002',
        'warehouse_name':'Kho HN',
    }]
    storage_quantity=[{
        'warehouse_id':'WH001',
        'product_id':'SP001',
        'quantity':20,
        'current_quantity':20},
        {
        'warehouse_id':'WH001',
        'product_id':'SP002',
        'quantity':10,
        'current_quantity':10},
        {
        'warehouse_id':'WH001',
        'product_id':'SP003',
        'quantity':5,
        'current_quantity':5},
        {
        'warehouse_id':'WH001',
        'product_id':'SP004',
        'quantity':15,
        'current_quantity':15},
        {
        'warehouse_id':'WH002',
        'product_id':'SP001',
        'quantity':10,
        'current_quantity':10},
        {
        'warehouse_id':'WH002',
        'product_id':'SP002',
        'quantity':20,
        'current_quantity':20},
        {
        'warehouse_id':'WH002',
        'product_id':'SP003',
        'quantity':10,
        'current_quantity':10},
        {
        'warehouse_id':'WH002',
        'product_id':'SP004',
        'quantity':25,
        'current_quantity':25}]
    transactions=[]
    def getProducts(self):
        return self.products
    def getStorages(self):
        return self.storages
    
    def calculateWarehouseStock(self,storage_quantity,storages):
        results=[]
        for storage in storages:
            warehouse_id=storage['warehouse_id']
            total_quantity=0
            for sq in storage_quantity:
                if sq['warehouse_id']==warehouse_id:
                    total_quantity+=sq['current_quantity']
            
            results.append({
                'warehouse_id':warehouse_id,
                'total_quantity':total_quantity
            })
        return results
    
    def calculateProductStock(self,storage_quantity,products):
        results=[]
        for product in products:
            product_id=product['product_id']
            total_quantity=0
            for sq in storage_quantity:
                if sq['product_id']==product_id:
                    total_quantity+=sq['current_quantity']
            
            results.append({
                'product_id':product_id,
                'total_quantity':total_quantity
            })
        return results
    
    def getCurrentQuantity(self,warehouse_id,product_id,storage_quantity):
        for sq in storage_quantity:
            if sq['warehouse_id']==warehouse_id and sq['product_id']==product_id:
                return sq['current_quantity']
        return 0
    
    def getQuantityManagement(self,storage_quantity,products):
        results=[]
        for product in products:
            results.append({
                'product_id':product['product_id'],
                'storage_quantity': {
                    'WH001': self.getCurrentQuantity('WH001', product['product_id'], storage_quantity),
                    'WH002': self.getCurrentQuantity('WH002', product['product_id'], storage_quantity)
                },
                'total_quantity': self.calculateProductStock(storage_quantity, [product])[0]['total_quantity']
            })
        return results
    
    def convertStringToArray(self,input,typePort):
        lines=input.splitlines()
        transactions=[]
        for line in lines:
            line=line.strip()
            if line:
                fields=line.split(',')
                if len(fields)==4:
                    transactions.append({
                        'transaction_id':fields[0].strip(),
                        'product_id':fields[1].strip(),
                        'type':typePort,
                        'quantity':fields[3].strip(),
                        'source_warehouse':fields[2].strip(),
                        'target_warehouse': None
                    })
                elif len(fields)==5:
                    transactions.append({
                        'transaction_id':fields[0].strip(),
                        'product_id':fields[1].strip(),
                        'type':typePort,
                        'quantity':fields[4].strip(),
                        'source_warehouse':fields[2].strip(),
                        'target_warehouse': fields[3].strip()
                    })
        return transactions

    def validateQuantityRange(self,quantity):
        quantity=quantity.lstrip('0')
        if quantity=='' or len(quantity)>len(str(sys.maxsize)) or (len(quantity)==len(str(sys.maxsize)) and quantity>str(sys.maxsize)):
            return False
        return True
    
    def validateTransaction(self,transactions):
        if not transactions:
            return "Transaction data is empty."
        for transaction in transactions:
            if transaction['transaction_id']=='':
                return "Mã giao dịch rỗng"
            elif transaction['transaction_id'] in [t['transaction_id'] for t in self.transactions]:
                return "Mã giao dịch bị trùng"
            elif transaction['product_id'] not in [t['product_id'] for t in self.products]:
                return "Mã sản phẩm không tồn tại"
            elif transaction['source_warehouse'] not in [t['warehouse_id'] for t in self.storages]:
                return "Mã kho nguồn không tồn tại"
            elif transaction['type'] not in self.typeP:
                return "Loại giao dịch không hợp lệ"
            elif transaction['type']=='transfer' and transaction['target_warehouse'] not in [t['warehouse_id'] for t in self.storages]:
                return "Mã kho đích không tồn tại"
            elif not re.match(r'^\d+$', transaction['quantity']):
                return "Số lượng phải là số nguyên dương"
            elif not self.validateQuantityRange(transaction['quantity']):
                return "Số lượng vượt quá giới hạn hoặc <= 0"
            elif transaction['type']=='export' and int(transaction['quantity'])>self.getCurrentQuantity(transaction['source_warehouse'],transaction['product_id'],self.storage_quantity):
                return "Số lượng xuất vượt quá tồn kho"
            elif transaction['type']=='transfer' and int(transaction['quantity'])>self.getCurrentQuantity(transaction['source_warehouse'],transaction['product_id'],self.storage_quantity):
                return "Số lượng chuyển vượt quá tồn kho"
            elif transaction['type']=='transfer' and transaction['source_warehouse']==transaction['target_warehouse']:
                return "Kho nguồn và kho đích không được trùng nhau"
        return "Valid transaction"
    
    def importStock(self,storage_quantity,transactions,newTransactions):
        for transaction in newTransactions:
            storage_quantity_import=next((sq for sq in storage_quantity if sq['warehouse_id']==transaction['source_warehouse'] and sq['product_id']==transaction['product_id']),None)
            if storage_quantity_import:
                storage_quantity_import['current_quantity']+=int(transaction['quantity'])
                for sq in storage_quantity:
                    if sq['warehouse_id']==transaction['source_warehouse'] and sq['product_id']==transaction['product_id']:
                        sq=storage_quantity_import
                        break
                transactions.append({
                    'transaction_id': transaction['transaction_id'],
                    'product_id': transaction['product_id'],
                    'type': transaction['type'],
                    'quantity': int(transaction['quantity']),
                    'source_warehouse': transaction['source_warehouse'],
                    'target_warehouse': None
                })
                return "Giao dịch nhập kho thành công"
    
    def exportStock(self,storage_quantity,transactions,newTransactions):
        for transaction in newTransactions:
            storage_quantity_export=next((sq for sq in storage_quantity if sq['warehouse_id']==transaction['source_warehouse'] and sq['product_id']==transaction['product_id']),None)
            if storage_quantity_export and storage_quantity_export['current_quantity']>=int(transaction['quantity']):
                storage_quantity_export['current_quantity']-=int(transaction['quantity'])
                for sq in storage_quantity:
                    if sq['warehouse_id']==transaction['source_warehouse'] and sq['product_id']==transaction['product_id']:
                        sq=storage_quantity_export
                        break
                transactions.append({
                    'transaction_id': transaction['transaction_id'],
                    'product_id': transaction['product_id'],
                    'type': transaction['type'],
                    'quantity': int(transaction['quantity']),
                    'source_warehouse': transaction['source_warehouse'],
                    'target_warehouse': None
                })
                return "Giao dịch xuất kho thành công"

    def transferStock(self,storage_quantity,transactions,newTransactions):
        for transaction in newTransactions:
            storage_quantity_transfer_source=next((sq for sq in storage_quantity if sq['warehouse_id']==transaction['source_warehouse'] and sq['product_id']==transaction['product_id']),None)
            storage_quantity_transfer_target=next((sq for sq in storage_quantity if sq['warehouse_id']==transaction['target_warehouse'] and sq['product_id']==transaction['product_id']),None)
            if storage_quantity_transfer_source and storage_quantity_transfer_target and storage_quantity_transfer_source['current_quantity']>=int(transaction['quantity']):
                storage_quantity_transfer_source['current_quantity']-=int(transaction['quantity'])
                storage_quantity_transfer_target['current_quantity']+=int(transaction['quantity'])
                for sq in storage_quantity:
                    if sq['warehouse_id']==transaction['source_warehouse'] and sq['product_id']==transaction['product_id']:
                        sq=storage_quantity_transfer_source
                    elif sq['warehouse_id']==transaction['target_warehouse'] and sq['product_id']==transaction['product_id']:
                        sq=storage_quantity_transfer_target
                transactions.append({
                    'transaction_id': transaction['transaction_id'],
                    'product_id': transaction['product_id'],
                    'type': transaction['type'],
                    'quantity': int(transaction['quantity']),
                    'source_warehouse': transaction['source_warehouse'],
                    'target_warehouse': transaction['target_warehouse']
                })
                return "Giao dịch chuyển kho thành công"
    
    def getLowStockProducts(self,products,storage_quantity):
        currentQuantityProduct=self.calculateProductStock(storage_quantity,products)
        low_stock_products=[]
        for productStock in currentQuantityProduct:
            product=next((p for p in products if p['product_id']==productStock['product_id']),None)
            if product and productStock['total_quantity']<=product['min_stock']:
                low_stock_products.append({
                    'product_id':product['product_id'],
                    'product_name':product['product_name'],
                    'total_current_quantity':productStock['total_quantity'],
                    'min_stock':product['min_stock']
                })
        return low_stock_products
    # Tính giá trị tồn kho từng sản phẩm theo kho
    def getInventoryValueByStorage(self,storage_quantity,products,storages):
        results={}
        for storage in storages:
            warehouse_id=storage['warehouse_id']
            results[warehouse_id]=[]
            for product in products:
                product_id=product['product_id']
                unit_price=product['unit_price']
                current_quantity=self.getCurrentQuantity(warehouse_id,product_id,storage_quantity)
                total_value=current_quantity*unit_price
                results[warehouse_id].append({
                    'product_id':product_id,
                    'current_quantity':current_quantity,
                    'unit_price':unit_price,
                    'total_value':total_value
                })
        return results
    
    def getInventoryStatistics(self,products,storage_quantity,storages,transactions):
        total_product=len(products)
        total_warehouses=len(storages)
        quantityByStorage=self.calculateWarehouseStock(storage_quantity,storages)
        total_quantity_count=sum(q['total_quantity'] for q in quantityByStorage)
        inventoryValue=self.getInventoryValueByStorage(storage_quantity,products,storages)
        total_quantity_value_storage={}
        for warehouse_id, product_values in inventoryValue.items():
            total_value=sum(pv['total_value'] for pv in product_values)
            total_quantity_value_storage[warehouse_id]=total_value
        total_quantity_value=sum(total_quantity_value_storage.values())
        totalImportQuantity=sum(int(t['quantity']) for t in transactions if t['type']=='import')
        totalExportQuantity=sum(int(t['quantity']) for t in transactions if t['type']=='export')
        totalTransferQuantity=sum(int(t['quantity']) for t in transactions if t['type']=='transfer')
        lowStockProductCount=len(self.getLowStockProducts(products,storage_quantity))
        return {
            'total_product': total_product,
            'total_warehouses': total_warehouses,
            'total_quantity_count': total_quantity_count,
            'total_quantity_value': total_quantity_value,
            'total_import_quantity': totalImportQuantity,
            'total_export_quantity': totalExportQuantity,
            'total_transfer_quantity': totalTransferQuantity,
            'low_stock_product_count': lowStockProductCount,
            'total_quantity_value_storage': total_quantity_value_storage
        }
    
    def getTransactionHistory(self,transactions):
        histories=[]
        for transaction in transactions:
            histories.append({
                'transaction_id': transaction['transaction_id'],
                'product_id': transaction['product_id'],
                'type': transaction['type'],
                'quantity': transaction['quantity'],
                'source_warehouse': transaction['source_warehouse'],
                'target_warehouse': transaction['target_warehouse'] if transaction['target_warehouse'] else '-'
            })
        return histories

    def filterTransactions(self,transactions,searchTerm,typeSearch):
        filteredTransactions=[]
        for transaction in transactions:
            if typeSearch =='warehouse':
                if (transaction['source_warehouse'] or transaction['target_warehouse']) and any(searchTerm.lower() in s.lower() for s in [transaction['source_warehouse'], transaction['target_warehouse'] if transaction['target_warehouse'] else '']):
                    filteredTransactions.append(transaction)
            else:
                if (transaction[typeSearch] and searchTerm.lower() in transaction[typeSearch].lower()):
                    filteredTransactions.append(transaction)
        return filteredTransactions
    
    def filterTransactionsByType(self,transactions,typePort):
        filteredTransactions=[]
        if typePort=='all':
            return transactions
        else:
            for transaction in transactions:
                if transaction['type']==typePort:
                    filteredTransactions.append(transaction)
        return filteredTransactions
    
    def index(self):
        error=""
        message=""
        input=""
        typeP=""
        productId=""
        warehouseId=""
        transactionId=""
        typeSort=""
        transactions=self.transactions
        products=self.products
        storages=self.storages
        storage_quantity=self.storage_quantity
        quantityManagement=self.getQuantityManagement(storage_quantity,products)
        calculateWareHouseStock=self.calculateWarehouseStock(storage_quantity,storages)
        inventoryValue=self.getInventoryValueByStorage(storage_quantity,products,storages)
        inventoryStatistics=self.getInventoryStatistics(products,storage_quantity,storages,transactions)
        lowStockProducts=self.getLowStockProducts(products,storage_quantity)
        transactionsHistory=self.getTransactionHistory(transactions)
        if request.method=='POST' and 'process' in request.form:
            input=request.form['input']
            typeP=request.form['typeP']
            newTransactions=self.convertStringToArray(input,typeP)
            validationResult=self.validateTransaction(newTransactions)
            if validationResult=="Valid transaction":
                if typeP=='import':
                    message=self.importStock(storage_quantity,self.transactions,newTransactions)
                elif typeP=='export':
                    message=self.exportStock(storage_quantity,self.transactions,newTransactions)
                elif typeP=='transfer':
                    message=self.transferStock(storage_quantity,self.transactions,newTransactions)
                transactions=self.transactions
                storage_quantity=self.storage_quantity
                transactionsHistory=self.getTransactionHistory(self.transactions)
                quantityManagement=self.getQuantityManagement(storage_quantity,products)
                calculateWareHouseStock=self.calculateWarehouseStock(storage_quantity,storages)
                inventoryValue=self.getInventoryValueByStorage(storage_quantity,products,storages)
                inventoryStatistics=self.getInventoryStatistics(products,storage_quantity,storages,self.transactions)
                lowStockProducts=self.getLowStockProducts(products,storage_quantity)
            else:
                error=validationResult
        
        if request.method=='POST' and 'filter' in request.form:
            transactionId=request.form['transaction_id']
            productId=request.form['product_id']
            warehouseId=request.form['warehouse_id']
            typeSort=request.form['typeSort']
            filteredTransactions=transactions
            if transactionId!="" :
                filteredTransactions=self.filterTransactions(filteredTransactions,transactionId,'transaction_id')
            if productId!="" :
                filteredTransactions=self.filterTransactions(filteredTransactions,productId,'product_id')
            if warehouseId!="" :
                filteredTransactions=self.filterTransactions(filteredTransactions,warehouseId,'warehouse')
            if typeSort!="":
                filteredTransactions=self.filterTransactionsByType(filteredTransactions,typeSort)
            transactionsHistory=filteredTransactions
        
        return render_template(
            'storage_management.html',
            error=error,
            message=message,
            input=input,
            typeP=typeP,
            productId=productId,
            warehouseId=warehouseId,
            transactionId=transactionId,
            typeSort=typeSort,
            transactionsHistory=transactionsHistory,
            products=products,
            storages=storages,
            storage_quantity=storage_quantity,
            quantityManagement=quantityManagement,
            calculateWareHouseStock=calculateWareHouseStock,
            inventoryValue=inventoryValue,
            inventoryStatistics=inventoryStatistics,
            lowStockProducts=lowStockProducts
        )