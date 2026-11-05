from flask import Flask
from controllers.Test import Test
 
app=Flask(__name__)

@app.route('/', methods=['GET', 'POST'])
def index():
    test=Test()
    return test.index()

if __name__=='__main__':
    app.run(debug=True)