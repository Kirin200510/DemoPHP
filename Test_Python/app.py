from flask import Flask
from controllers.BookController import BookController

app=Flask(__name__)
controllers=BookController()

@app.route('/',methods=['GET','POST'])
def index():
    return controllers.index()

if __name__=='main':
    app.run(debug=True)

