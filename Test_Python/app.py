from flask import Flask
from controllers.BookController import BookController

app=Flask(__name__)
controllers=BookController()

@app.route('/',methods=['GET','POST'])
def index():
    return controllers.index()

if __name__=="__main__":
    app.run(debug=True,port=8002)

