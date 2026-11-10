from flask import Flask
from controllers.OrderAdvanceController import OrderAdvanceController

app = Flask(__name__)

@app.route('/', methods=['GET', 'POST'])
def index():
    test=OrderAdvanceController()
    return test.index()

if __name__ == '__main__':
    app.run(debug=True,port=5000)