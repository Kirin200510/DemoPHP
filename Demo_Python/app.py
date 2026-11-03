
from operator import index
from flask import Flask

app = Flask(__name__)
# gan ham index been Test.py vao app.py
@app.route('/', methods=['GET', 'POST'])
def index():
    from controllers.Test import index as test_index
    return test_index()
if __name__ == "__main__":
    app.run(debug=True, port=2001)