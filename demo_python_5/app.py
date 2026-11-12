from flask import Flask
from controllers.StorageManagementController import StorageManagementController
app=Flask(__name__)


@app.route('/', methods=['GET','POST'])
def index():
    controller=StorageManagementController()
    return controller.index()

if __name__ == '__main__':
    app.run(debug=True,port=5000)