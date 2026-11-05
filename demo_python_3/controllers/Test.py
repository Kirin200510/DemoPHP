import re,sys
from flask import request,render_template
class Test:
    status=['pending', 'processing', 'completed', 'cancelled']
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
    
    def validateInputOrders(self , orders):
        if (orders=={}):
            return 'Dữ liệu đơn hàng không hợp lệ'
        for order in orders.values():
            if order['order_id'].strip()=='':
                return 'Mã đơn hàng không được để trống'
            elif len([o for o in orders.values() if o['order_id'].strip()==order['order_id'].strip()])>1:
                return 'Mã đơn hàng bị trùng lặp'
            elif order['customer_name'].strip()=='':
                return 'Tên khách hàng không được để trống'
            elif re.match(r'^[A-Za-z\s]+$', order['customer_name'].strip()) is None:
                return 'Tên khách hàng chỉ chứa các ký tự chữ cái và khoảng trắng giữa các từ(nếu có)'
            elif re.match(r'^[0-9]+$', order['amount'].strip()) is None:
                return 'Giá trị đơn hàng phải là số nguyên'
            elif not self.validateAmountRange(order['amount']):
                return 'Giá trị đơn hàng phải là số nguyên dương'
            elif order['status'].strip() not in self.status:
                return 'Trạng thái đơn hàng không hợp lệ'
        return 'Valid input'
    
    def parseOrders(self, orders):
        parsed_orders=[]
        for order in orders.values():
            parsed_order={
                'order_id':order['order_id'].strip(),
                'customer_name':order['customer_name'].strip(),
                'status':order['status'].strip(),
                'amount':int(order['amount'].strip())
            }
            parsed_orders.append(parsed_order)
        return parsed_orders
    
    def getStatisticOrders(self, orders):
        totalOrders=len(orders)
        totalAmount=sum(order['amount'] for order in orders)
        statusPending=len([order for order in orders if order['status']=='pending'])
        statusProcessing=len([order for order in orders if order['status']=='processing'])
        statusCompleted=len([order for order in orders if order['status']=='completed'])
        statusCancelled=len([order for order in orders if order['status']=='cancelled'])
        totalAmountComplete=sum(order['amount'] for order in orders if order['status']=='completed')
        maxAmountOrder=max(order['amount'] for order in orders)
        maxAmountOrderDetail=(order for order in orders if order['amount']==maxAmountOrder).__next__()
        return {
            'totalOrders':totalOrders,
            'totalAmount':totalAmount,
            'statusPending':statusPending,
            'statusProcessing':statusProcessing,
            'statusCompleted':statusCompleted,
            'statusCancelled':statusCancelled,
            'totalAmountComplete':totalAmountComplete,
            'maxAmountOrder':maxAmountOrderDetail['order_id']+"-"+str(maxAmountOrderDetail['amount'])
        }
    
    def getOrderByStatus(self, orders,status):
        if status.strip()=='all':
            return orders
        else:
            return [order for order in orders if order['status']==status.strip()]
    
    def sortAmountASCOrDEC(self, orders, sort):
        if sort.strip()=='asc':
            return sorted(orders, key=lambda x: x['amount'])
        elif sort.strip()=='desc':
            return sorted(orders, key=lambda x: x['amount'], reverse=True)
        else: # all
            return orders
    
    def getOrderSecondMaxAmountWithoutSort(self, orders):
        maxAmount=max(order['amount'] for order in orders)
        secondMaxAmount=None
        for order in orders:
            if order['amount']<maxAmount:
                if secondMaxAmount is None or order['amount']>secondMaxAmount:
                    secondMaxAmount=order['amount']
        if secondMaxAmount is None:
            return []
        else:
            return [order for order in orders if order['amount']==secondMaxAmount]
                
    def getOrderCompleteByCustomer(self, orders, customer_name):
        return [order for order in orders if order['customer_name'].lower()==customer_name.strip().lower() and order['status']=='completed']

    def totalAmountByCustomer(self, orders, customer_name):
       completeOrder=self.getOrderCompleteByCustomer(orders, customer_name)
       if completeOrder==[]:
           return 0
       else:
           return sum(order['amount'] for order in completeOrder)

    def typeCustomer(self, orders):
        uniqueCustomerNames=set(order['customer_name'] for order in orders)
        results={}
        for customer_name in uniqueCustomerNames:
            results[customer_name]=self.totalAmountByCustomer(orders, customer_name)

        if len(results)==0:
            return {}
        else: 
            for customer_name in results:
                if results[customer_name]==0:
                    results[customer_name]='No complete order'
                elif results[customer_name]>0 and results[customer_name]<2000000:
                    results[customer_name]='New'
                elif results[customer_name]>=2000000 and results[customer_name]<5000000:
                    results[customer_name]='Regular'
                else:
                    results[customer_name]='VIP'
            return results
    
    def index(self):
        error=""
        validateMessage=""
        orders={}
        results={}
        statuses=self.status
        secondMaxOrder=[]
        typeCustomers={}
        sort=""
        status=""
        input=""
        if request.method=='POST' and 'input' in request.form:
            input=request.form.get('input')
            if input.strip()=='':
                error='Vui lòng nhập dữ liệu đơn hàng'
            else:
                orders=self.convertStringToArray(input)
                validateMessage=self.validateInputOrders(orders)
                if validateMessage=='Valid input':
                    orders=self.parseOrders(orders)
                    results=self.getStatisticOrders(orders)
                    secondMaxOrder=self.getOrderSecondMaxAmountWithoutSort(orders)
                    typeCustomers=self.typeCustomer(orders)
                else:
                    error=validateMessage
                    orders=[]
                    input=""
        if request.method=='POST' and 'status' in request.form:
            status=request.form.get('status')
            print(status)
            if status not in self.status and status.strip()!='all':
                error='Trạng thái đơn hàng không hợp lệ'
            else:
                orders=self.getOrderByStatus(orders, status)
        if request.method=='POST' and 'sort' in request.form:
            sort=request.form.get('sort')
            if sort.strip() not in ['asc', 'desc', 'all']:
                error='Sắp xếp đơn hàng không hợp lệ'
            else:
                orders=self.sortAmountASCOrDEC(orders, sort)
        return render_template('index.html', error=error, orders=orders, results=results, statuses=statuses, secondMaxOrder=secondMaxOrder, typeCustomers=typeCustomers,input=input,status=status,sort=sort)
