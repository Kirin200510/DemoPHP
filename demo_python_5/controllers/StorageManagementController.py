import re,sys
from flask import render_template,request

class StorageManagementController:
    typePort=['import','export']
    typeStock=['In Stock','Low Stock']
    products=[{
                'product_id': 'SP001',
                'product_name': 'Chuột máy tính',
                'unit_price': 150000,
                'quantity': 20,
                'current_quantity': 20,
                'min_stock': 5
            },
            {
                'product_id': 'SP002',
                'product_name': 'Bàn phím',
                'unit_price': 450000,
                'quantity': 15,
                'current_quantity': 15,
                'min_stock': 3
            },
            {
                'product_id': 'SP003',
                'product_name': 'Màn hình',
                'unit_price': 2500000,
                'quantity': 8,
                'current_quantity': 8,
                'min_stock': 2
            },
            {
                'product_id': 'SP004',
                'product_name': 'USB',
                'unit_price': 120000,
                'quantity': 30,
                'current_quantity': 30,
                'min_stock': 10
            },
            {
                'product_id': 'SP005',
                'product_name': 'Tai nghe',
                'unit_price': 650000,
                'quantity': 10,
                'current_quantity': 10,
                'min_stock': 2
            }
    ]
    transactions=[]
    def updateStatusStock(self,product):
        if (product['current_quantity'] <= product['min_stock']):
            product['status'] = self.typeStock[1]
        else:
            product['status'] = self.typeStock[0]
        return product

    def getProducts(self):
        prods=self.products
        for product in prods:
            product=self.updateStatusStock(product)
        return prods
    
    def getTransactions(self):
        return self.transactions
    
    def convertStringToArray(self, input,typeP):
        lines=input.splitlines()
        transactions=[]
        for line in lines:
            line=line.strip()
            if line:
                fields=line.split(',')
                if len(fields) == 3:
                    transaction={
                        'transaction_id': fields[0].strip(),
                        'product_id': fields[1].strip(),
                        'type': typeP,
                        'quantity': fields[2].strip()
                    }
                    transactions.append(transaction)
                else:
                    return []
        return transactions

    def validateQuantityRange(self,range):
        quantity=range.lstrip('0')
        if quantity=='' or len(quantity)>len(str(sys.maxsize)) or (len(quantity)==len(str(sys.maxsize)) and quantity>str(sys.maxsize)):
            return False
        return True
    
    def validateTransaction(self,transactions):
        if not transactions:
            return "Transaction data is empty"
        for transaction in transactions:
            if transaction['transaction_id'] == '':
                return "Mã giao dịch rỗng"
            elif transaction['transaction_id'] in [t['transaction_id'] for t in self.transactions]:
                return "Mã giao dịch đã tồn tại"
            elif transaction['type'] not in self.typePort:
                return "Loại giao dịch không hợp lệ"
            elif transaction['product_id'] not in [product['product_id'] for product in self.products]:
                return "Mã sản phẩm không tồn tại"
            elif not re.match(r'^\d+$', transaction['quantity']):
                return "Số lượng không phải là số nguyên"
            elif not self.validateQuantityRange(transaction['quantity']):
                return "Số lượng phải là số nguyên dương và không vượt quá giới hạn của hệ thống"
            elif transaction['type'] == 'export':
                product=next((product for product in self.products if product['product_id'] == transaction['product_id']), None)
                if product and int(transaction['quantity']) > product['current_quantity']:
                    return "Số lượng xuất kho vượt quá số lượng tồn kho hiện tại"
        return "Valid transaction"
    
    def addTransactionImport(self,products,transactions,newTransactions):
        for newTransaction in newTransactions:
            product=next((product for product in products if product['product_id'] == newTransaction['product_id']), None)
            if product and newTransaction['type'] == 'import':
                product['current_quantity'] += int(newTransaction['quantity'])
                product=self.updateStatusStock(product)
                for p in products:
                    if p['product_id'] == product['product_id']:
                        p=product
                        break
                transactions.append({
                    'transaction_id': newTransaction['transaction_id'].strip(),
                    'product_id': newTransaction['product_id'].strip(),
                    'type': 'import',
                    'quantity': int(newTransaction['quantity'].strip())
                })
                return "Giao dịch nhập kho đã được thêm thành công"
            else:
                return "Giao dịch nhập kho không hợp lệ"
                
    
    def addTransactionExport(self,products,transactions,newTransactions):
        for newTransaction in newTransactions:
            product=next((product for product in products if product['product_id'] == newTransaction['product_id']), None)
            if product and newTransaction['type'] == 'export':
                if int(newTransaction['quantity']) <= product['current_quantity']:
                    product['current_quantity'] -= int(newTransaction['quantity'])
                    product=self.updateStatusStock(product)
                    for p in products:
                        if p['product_id'] == product['product_id']:
                            p=product
                            break
                    transactions.append({
                        'transaction_id': newTransaction['transaction_id'].strip(),
                        'product_id': newTransaction['product_id'].strip(),
                        'type': 'export',
                        'quantity': int(newTransaction['quantity'].strip())
                    })
                    return "Giao dịch xuất kho đã được thêm thành công"
                else:
                    return "Số lượng xuất kho vượt quá số lượng tồn kho hiện tại"
            else:
                return "Giao dịch xuất kho không hợp lệ"
            
    def calculateStockByProducts(self,products,transactions):
        stockByProducts=[]
        for product in products:
            totalProductImport=sum(int(transaction['quantity']) for transaction in transactions if transaction['product_id'] == product['product_id'] and transaction['type'] == 'import')
            totalProductExport=sum(int(transaction['quantity']) for transaction in transactions if transaction['product_id'] == product['product_id'] and transaction['type'] == 'export')
            currentStock=product['current_quantity']
            stockByProducts.append({
                'product_id': product['product_id'],
                'product_name': product['product_name'],
                'unit_price': product['unit_price'],
                'total_import': totalProductImport,
                'total_export': totalProductExport,
                'current_stock': currentStock,

                'quantity': product['quantity'],
                'value_stock': currentStock * product['unit_price']
            })
        return stockByProducts
    # Trả về kiểu dữ liệu dict
    def getStockStatistics(self,products,transactions):
        totalProducts=len(products)
        totalImport=sum(int(transaction['quantity']) for transaction in transactions if transaction['type'] == 'import')
        totalExport=sum(int(transaction['quantity']) for transaction in transactions if transaction['type'] == 'export')
        productLowStockCount=sum(1 for product in products if self.updateStatusStock(product)['status'] == self.typeStock[1])
        stockByProducts=self.calculateStockByProducts(products,transactions)
        totalPresentStorage=0
        totalCurrentStock=0
        for product in stockByProducts:
            totalPresentStorage += product['value_stock']
            totalCurrentStock += product['current_stock']
        return {
            'total_products': totalProducts,
            'total_import': totalImport,
            'total_export': totalExport,
            'total_present_storage': totalPresentStorage,
            'total_current_stock': totalCurrentStock,
            'product_low_stock_count': productLowStockCount
        }
    
    def getLowStockProducts(self,products,transactions):
        lowStockProducts=[]
        for product in products:
            if self.updateStatusStock(product)['status'] == self.typeStock[1]:
                lowStockProducts.append({
                    'product_id': product['product_id'],
                    'product_name': product['product_name'],
                    'current_quantity': product['current_quantity'],
                    'min_stock': product['min_stock'],
                    'resupply': product['min_stock'] - product['current_quantity'] if product['current_quantity'] < product['min_stock'] else 0
                })
        return lowStockProducts

    def filterProductsByIdAndName(self,products,searchTerm):
        filteredProducts=[]
        for product in products:
            if searchTerm.lower() in product['product_id'].lower() or searchTerm.lower() in product['product_name'].lower():
                filteredProducts.append(product)
        return filteredProducts
    
    def filterProductsByStatus(self,products,status):
        filteredProducts=[]
        if status=='all':
            return products
        else:
            for product in products:
                if self.updateStatusStock(product)['status'] == status:
                    filteredProducts.append(product)
        return filteredProducts
    
    def sortProducts(self, products,transactions,sort,typeSort):
        if typeSort=='quantity':
            if sort=='asc':
                return sorted(products, key=lambda x: x['current_quantity'])
            elif sort=='desc':
                return sorted(products, key=lambda x: x['current_quantity'], reverse=True)
            elif sort=='all':
                return products
        elif typeSort=='value_stock':
            stockByProducts=self.calculateStockByProducts(products,transactions)
            result=[]
            finalSortProducts=[]
            if sort=='asc':
                result=sorted(stockByProducts, key=lambda x: x['value_stock'])
                sortedIdByValue=[product['product_id'] for product in result]
                for productId in sortedIdByValue:
                    product=next((product for product in products if product['product_id'] == productId), None)
                    if product:
                        finalSortProducts.append(product)
                return finalSortProducts

            elif sort=='desc':
                result=sorted(stockByProducts, key=lambda x: x['value_stock'], reverse=True)
                sortedIdByValue=[product['product_id'] for product in result]
                for productId in sortedIdByValue:
                    product=next((product for product in products if product['product_id'] == productId), None)
                    if product:
                        finalSortProducts.append(product)
                return finalSortProducts
            elif sort=='all':
                return products
    
    def getCheckTransactionHistory(self,transactions):
        history=[]
        transactionImportCount=sum(1 for transaction in transactions if transaction['type'] == 'import')
        transactionExportCount=sum(1 for transaction in transactions if transaction['type'] == 'export')
        totalImport=self.getStockStatistics(self.products,transactions)['total_import']
        totalExport=self.getStockStatistics(self.products,transactions)['total_export']
        history={
            'total_import_transactions': transactionImportCount,
            'total_export_transactions': transactionExportCount,
            'total_import_quantity': totalImport,
            'total_export_quantity': totalExport
        }
        return history
    
    def getTransactionHistory(self,transactions,products):
        history=[]
        for transaction in transactions:
            if transaction['type']=='import':
                result='Nhập thành công'
            elif transaction['type']=='export':
                result='Xuất thành công'
            else:
                result='Giao dịch thất bại'
            history.append({
                'transaction_id': transaction['transaction_id'],
                'product_id': transaction['product_id'],
                'product_name': products[next((index for (index, d) in enumerate(products) if d["product_id"] == transaction['product_id']), None)]['product_name'] if next((index for (index, d) in enumerate(products) if d["product_id"] == transaction['product_id']), None) is not None else '',
                'type': transaction['type'],
                'quantity': transaction['quantity'],
                'result': result
            })
        return history
    
    def filterTransaction(self,transactions,searchTerm):
        filteredTransactions=[]
        for transaction in transactions:
            if searchTerm.lower() in transaction['product_id'].lower() or searchTerm.lower() in transaction['type'].lower():
                filteredTransactions.append(transaction)
        return filteredTransactions

    def findMaxProductExport(self,transactions,products):
        stockProduct=self.calculateStockByProducts(products,transactions)
        maxExportCount=max((product['total_export'] for product in stockProduct), default=0)
        maxExportProducts=[]
        maxExportProducts=[product for product in stockProduct if product['total_export'] == maxExportCount]
        if maxExportCount==0:
            return []
        return {
            'max_export_products': maxExportProducts,
            'max_export_count': maxExportCount
        }
    
    def index(self):
        input=""
        typeP=""
        error=""
        message=""
        productId=""
        productName=""
        status="all"
        sort="all"
        typeSort="quantity"
        productIdTransaction=""
        typeTransaction=""

        products=self.getProducts() 
        transactions=self.getTransactions()
        calculateStock=self.calculateStockByProducts(products,transactions)
        stockStatistics=self.getStockStatistics(products,transactions)
        lowStockProducts=self.getLowStockProducts(products,transactions)
        transactionHistory=self.getCheckTransactionHistory(transactions)
        transactionHistoryList=self.getTransactionHistory(transactions,products)
        maxExportProducts=self.findMaxProductExport(transactions,products)
        if request.method=='POST' and 'process' in request.form:
            input=request.form['input']
            typeP=request.form['type']
            newTransactions=self.convertStringToArray(input,typeP)
            validationMessage=self.validateTransaction(newTransactions)
            if validationMessage=="Valid transaction":
                if typeP=='import':
                    message=self.addTransactionImport(products,transactions,newTransactions)
                elif typeP=='export':
                    message=self.addTransactionExport(products,transactions,newTransactions)
                products=self.getProducts()
                transactions=self.getTransactions()
                calculateStock=self.calculateStockByProducts(products,transactions)
                stockStatistics=self.getStockStatistics(products,transactions)
                lowStockProducts=self.getLowStockProducts(products,transactions)
                transactionHistory=self.getCheckTransactionHistory(transactions)
                transactionHistoryList=self.getTransactionHistory(transactions,products)
                maxExportProducts=self.findMaxProductExport(transactions,products)
            else:
                error=validationMessage
        
        # filter and sort products
        if request.method=='POST' and 'filter' in request.form:
            productId=request.form['product_id']
            productName=request.form['product_name']
            status=request.form['status']
            sort=request.form['sort']
            typeSort=request.form['type_sort']
            products=self.filterProductsByIdAndName(products,productId)
            products=self.filterProductsByIdAndName(products,productName)
            products=self.filterProductsByStatus(products,status)
            products=self.sortProducts(products,transactions,sort,typeSort)
        
        # filter transactions
        if request.method=='POST' and 'filter_transaction' in request.form:
            productIdTransaction=request.form['transaction_product_id']
            typeTransaction=request.form['type_transaction']
            transactionHistoryList=self.filterTransaction(transactionHistoryList,productIdTransaction)
            transactionHistoryList=self.filterTransaction(transactionHistoryList,typeTransaction)

        return render_template(
            'storage_management.html', 
            error=error, 
            message=message, 
            input=input, 
            typeP=typeP, 
            productId=productId,
            productName=productName,
            status=status,
            sort=sort,
            typeSort=typeSort,
            productIdTransaction=productIdTransaction,
            typeTransaction=typeTransaction,
            products=products, 
            transactions=transactions, 
            calculateStock=calculateStock, 
            stockStatistics=stockStatistics, 
            lowStockProducts=lowStockProducts, 
            transactionHistory=transactionHistory, 
            transactionHistoryList=transactionHistoryList, 
            maxExportProducts=maxExportProducts
        )