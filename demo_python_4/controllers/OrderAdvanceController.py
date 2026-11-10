import re, sys
from flask import render_template,request

class OrderAdvanceController:
    status=['pending','processing','completed','cancelled']
    orders=[
        {'order_id': 'DH001', 'customer_name': 'Alice', 'amount': 1000000, 'status': 'completed'},
        {'order_id': 'DH002', 'customer_name': 'Bob', 'amount': 2000000, 'status': 'cancelled'},
        {'order_id': 'DH003', 'customer_name': 'Charlie', 'amount': 3000000, 'status': 'processing'}, 
        {'order_id': 'DH004', 'customer_name': 'David', 'amount': 4000000, 'status': 'pending'}
    ]
    def convertStringToArray(self, input):
        lines=input.splitlines()
        orders={}
        id=0
        for line in lines:
            line=line.strip()
            if line:
                parts=line.split(',')
                if len(parts)==4:
                    order={
                        'order_id':parts[0].strip(),
                        'customer_name':parts[1].strip(),
                        'status':parts[3].strip(),
                        'amount':parts[2].strip()
                    }
                    orders[id]=order
                    id+=1
                else:
                    return []
        return orders
    def validateAmountRange(self, amount):
        amount=amount.strip().lstrip('0')
        if amount=='' or len(amount)>len(str(sys.maxsize)) or (len(amount)==len(str(sys.maxsize)) and amount>str(sys.maxsize)):
            return False
        else:
            return int(amount)>=1
    
    def validateInputFilter(self, customerName, status, minAmount, maxAmount):
        message=""
        if (status and status not in self.status and status!='all'):
            message+="Trạng thái không hợp lệ. "
        if (minAmount<0 or maxAmount<0):
            message+="Số tiền không được âm. "
        if (minAmount>maxAmount):
            message+="Số tiền tối thiểu không được lớn hơn số tiền tối đa. "
        if (customerName and not re.match(r'^[a-zA-Z0-9\s]+$', customerName)):
            message+="Tên khách hàng không hợp lệ. "
        if message=="":
            message+="Valid input"
        return message

    def validateOrder(self, orders):
        if not orders:
            return "Order is required"
        if not isinstance(orders, dict):
            return "Orders must be a dictionary"
        for order in orders.values():
            if order.get('order_id')=='' or order.get('customer_name')=='':
                return "Mã đơn hàng và tên khách hàng là bắt buộc"
            elif re.match(r'^[A-Za-z\s]+$', order['customer_name'].strip()) is None:
                return "Tên khách hàng không hợp lệ"
            elif re.match(r'^[0-9]+$', order['amount'].strip()) is None:
                return 'Giá trị đơn hàng phải là số nguyên'
            elif not self.validateAmountRange(order['amount']):
                return 'Giá trị đơn hàng phải là số nguyên dương'
            elif order['status'] not in self.status:
                return 'Trạng thái đơn hàng không hợp lệ'
        return "Valid input"

    def addOrder(self, orders, newOrder):
        for order in newOrder.values():
            orders.append({
                'order_id': order['order_id'].strip(),
                'customer_name': order['customer_name'].strip(),
                'amount': int(order['amount'].strip()),
                'status': order['status'].strip()
            })
        return "Thêm đơn hàng thành công"
    
    def filterCustomerName(self, orders, customerName):
        filteredOrders = []
        for order in orders:
            
            if customerName.lower() in order['customer_name'].lower():
                filteredOrders.append(order)
        return filteredOrders 
    
    def filterOrders(self, orders, status):
        if status == 'all':
            return orders
        filteredOrders = []
        for order in orders:
            if order['status'] == status:
                filteredOrders.append(order)
        return filteredOrders
    
    def filterOrderAmount(self, orders, minAmount, maxAmount=sys.maxsize):
        filteredOrders = []
        for order in orders:
            if minAmount <= order['amount'] < maxAmount:
                filteredOrders.append(order)
        return filteredOrders

    def sortOrder(self, orders, sortBy):
        if sortBy.strip()=='asc':
            return sorted(orders, key=lambda x: x['amount'])
        elif sortBy.strip()=='desc':
            return sorted(orders, key=lambda x: x['amount'], reverse=True)
        else:
            return orders

    def getOrders(self):
        return self.orders

    def calculateStatistic(self, orders):
        totalOrders = len(orders)
        totalAmount = sum(order['amount'] for order in orders)
        completedCount=sum(1 for order in orders if order['status'] == 'completed')
        pendingCount=sum(1 for order in orders if order['status'] == 'pending')
        processingCount=sum(1 for order in orders if order['status'] == 'processing')
        cancelledCount=sum(1 for order in orders if order['status'] == 'cancelled')
        revenue=sum(order['amount'] for order in orders if order['status'] == 'completed')
        percentageStatuses={
            'completed': (completedCount / totalOrders) * 100 if totalOrders > 0 else 0,
            'pending': (pendingCount / totalOrders) * 100 if totalOrders > 0 else 0,
            'processing': (processingCount / totalOrders) * 100 if totalOrders > 0 else 0,
            'cancelled': (cancelledCount / totalOrders) * 100 if totalOrders > 0 else 0
        }
        return {
            'total_orders': totalOrders,
            'total_amount': totalAmount,
            'completed_count': completedCount,
            'pending_count': pendingCount,
            'processing_count': processingCount,
            'cancelled_count': cancelledCount,
            'revenue': revenue,
            'percentage_statuses': percentageStatuses
        }
    def groupOrdersByCustomer(self, orders):
        groupedOrders = {}
        for order in orders:
            customerName = order['customer_name']
            if customerName not in groupedOrders:
                groupedOrders[customerName] = []
            groupedOrders[customerName].append(order)
        return groupedOrders
    
    def calculateCustomerRevenue(self, groupedOrders):
        customerRevenue = {}
        for customerName, orders in groupedOrders.items():
            revenue = sum(order['amount'] for order in orders if order['status'] == 'completed')
            customerRevenue[customerName] = revenue
        return customerRevenue
    
    def classifyCustomersByRevenue(self, customerRevenue):
        results = {}
        for customerName, revenue in customerRevenue.items():
            if revenue < 1000000:
                results[customerName]='New'
            elif revenue < 3000000:
                results[customerName] = 'Regular'
            else:
                results[customerName] = 'Vip'
        return results

    def getStatisticByCustomer(self, orders):
        groupedOrders=self.groupOrdersByCustomer(orders)
        customerRevenue = self.calculateCustomerRevenue(groupedOrders)
        customerClassification = self.classifyCustomersByRevenue(customerRevenue)
        statisticCustomer={};
        for customerName in customerRevenue.keys():
            statisticCustomer[customerName] = {
                'total_orders': len(groupedOrders[customerName]),
                'total_amount': sum(order['amount'] for order in groupedOrders[customerName]),
                'revenue': customerRevenue[customerName],
                'classification': customerClassification[customerName],
                'complete_orders': sum(1 for order in groupedOrders[customerName] if order['status'] == 'completed'),
                'cancelled_orders': sum(1 for order in groupedOrders[customerName] if order['status'] == 'cancelled'), 
                'percent_cancelled': self.percentCancelledAnalyst(groupedOrders[customerName])
            }
        return statisticCustomer
    
    def findTopRevenueCustomer(self, customerRevenue, topN=3):
        topRevenueCustomers={}
        rank=0
        prevRevenue=None
        customerRevenue=dict(sorted(customerRevenue.items(), key=lambda x: x[1], reverse=True))
        for customerName, revenue in customerRevenue.items():
            if rank>=topN and revenue<prevRevenue:
                break
            elif rank<topN or revenue==prevRevenue:
                topRevenueCustomers[customerName] = revenue
            
            rank+=1
            prevRevenue=revenue
        return topRevenueCustomers

    def findTopAmountOrder(self, orders, topN=3):
        sortedOrders=sorted(orders, key=lambda x: x['amount'], reverse=True)
        topAmountOrders=[]
        rank=0
        prevAmount=None
        for order in sortedOrders:
            if rank>=topN and order['amount']<prevAmount:
                break
            elif rank<topN or order['amount']==prevAmount:
                topAmountOrders.append(order)
            rank+=1
            prevAmount=order['amount']
        return topAmountOrders

    def AbnormalOrder(self,orders):
        customerCancelTwoOrder={}
        groupedOrders=self.groupOrdersByCustomer(orders)
        for customerName in groupedOrders:
            # chỗ này ảnh hưởng đến over amount phía dứi
            results=groupedOrders[customerName]
            cancelledCount=sum(1 for order in results if order['status']=='cancelled')
            if cancelledCount >= 2:
                customerCancelTwoOrder[customerName] = cancelledCount

        orderAmountOver={}
        for order in orders:
            if (order['amount']) > 3000000:
                orderAmountOver[order['order_id']] = order['amount']
        orderId=[order['order_id'] for order in orders]
        duplicateOrderId=set(order_id for order_id in orderId if orderId.count(order_id) > 1)
        return {
            'customer_cancelled_two_orders': customerCancelTwoOrder,
            'order_amount_over_3M': orderAmountOver,
            'duplicate_order_ids': duplicateOrderId
        }

    def percentCancelledAnalyst(self,groupOrders):
        totalOrder=len(groupOrders)
        cancelledCount=sum(1 for order in groupOrders if order['status']=='cancelled')
        if totalOrder>0:
            return (cancelledCount/totalOrder)*100
        else:
            return 0
    
    def findMaxPercentCancelledCustomer(self, statisticCustomer):
        maxPercent=0
        maxCustomer=[]
        for customerName in statisticCustomer:
            stats=statisticCustomer[customerName]
            if stats['percent_cancelled']>maxPercent:
                maxPercent=stats['percent_cancelled']
                maxCustomer=[customerName]
            elif stats['percent_cancelled']==maxPercent and maxCustomer is not None:
                maxCustomer.append(customerName)
        return maxCustomer, maxPercent 
    

    def index(self):
        error=""
        message=""
        orders=None
        statistics=None
        statisticCustomer=None
        maxPercentCancelledCustomer=None
        topRevenueCustomers=None
        topAmountOrders=None
        abnormalOrders=None
        input1=""
        customerName=""
        filterStatus="all"
        minAmount=0
        maxAmount=sys.maxsize
        sortBy="all"
        orders=self.getOrders()
        statistics=self.calculateStatistic(orders) 
        statisticCustomer=self.getStatisticByCustomer(orders) 
        maxPercentCancelledCustomer=self.findMaxPercentCancelledCustomer(statisticCustomer)
        topRevenueCustomers=self.findTopRevenueCustomer(self.calculateCustomerRevenue(self.groupOrdersByCustomer(orders)))
        topAmountOrders=self.findTopAmountOrder(orders)
        abnormalOrders=self.AbnormalOrder(orders)
        statuses=self.status
        if request.method=='POST' and 'input' in request.form and 'filter' not in request.form and 'sort' not in request.form:
            input1=request.form.get('input', '').strip()
            result=self.convertStringToArray(input1)
            validationResult=self.validateOrder(result)
            if validationResult!="Valid input":
                error=validationResult
            else:
                message=self.addOrder(orders, result)
                statistics=self.calculateStatistic(orders)
                statisticCustomer=self.getStatisticByCustomer(orders)
                orders=self.getOrders()
                statisticCustomer=self.getStatisticByCustomer(orders)
                maxPercentCancelledCustomer=self.findMaxPercentCancelledCustomer(statisticCustomer)
                topRevenueCustomers=self.findTopRevenueCustomer(self.calculateCustomerRevenue(self.groupOrdersByCustomer(orders)))
                topAmountOrders=self.findTopAmountOrder(orders)
                abnormalOrders=self.AbnormalOrder(orders)
        
        if request.method=='POST' and 'filter' in request.form:
            customerName=request.form.get('customer_name', '').strip()
            filterStatus=request.form.get('status', 'all').strip()
            minAmount=int(request.form.get('min_amount') or 0)
            maxAmount=int(request.form.get('max_amount') or sys.maxsize)
            validationResult=self.validateInputFilter(customerName, filterStatus, minAmount, maxAmount)
            if validationResult!="Valid input":
                error=validationResult
            else:
                filteredOrders=self.filterCustomerName(orders, customerName)
                filteredOrders=self.filterOrders(filteredOrders, filterStatus)
                filteredOrders=self.filterOrderAmount(filteredOrders, minAmount, maxAmount)
                orders=filteredOrders
            
        if request.method=='POST' and 'sort' in request.form:
            sortBy=request.form.get('sort', '').strip()
            orders=self.sortOrder(orders, sortBy)
        return render_template('order_advance.html', orders=orders, statistics=statistics, statisticCustomer=statisticCustomer, maxPercentCancelledCustomer=maxPercentCancelledCustomer, topRevenueCustomers=topRevenueCustomers, topAmountOrders=topAmountOrders, abnormalOrders=abnormalOrders, statuses=statuses, error=error, message=message,input=input1,customer_name=customerName,status=filterStatus,min_amount=minAmount,max_amount=maxAmount,sort=sortBy)
