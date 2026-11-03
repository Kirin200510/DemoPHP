import re,sys
from flask import request, render_template,Flask
def convertToCanChi(year):
    can = [ "Giap", "At", "Binh", "Dinh", "Mau", "Ky", "Canh", "Tan", "Nham", "Quy"]
    chi = ["Ty", "Suu", "Dan", "Meo", "Thin", "Ty", "Ngo", "Mui", "Than", "Dau", "Tuat", "Hoi"]
    canIndex = (year - 4) % 10
    chiIndex = (year - 4) % 12
    return can[canIndex] + " " + chi[chiIndex]


def index():
    result = ""
    error = ""
    year = None
    year=request.form.get("year", None)
    if year is None:
        error = "Please require input year"
    else:
        year=year.strip()
        if year == "":
            error = "Vui long nhap nam"
        if not re.match(r'[0-9]+$', year):
            error = "Nam khong hop le"
        else:
            year=year.lstrip('0')
            if year=="" or len(year)>len(str(sys.maxsize)) or (len(year)==len(str(sys.maxsize)) and year>str(sys.maxsize)):
                error = "Nam khong hop le"
            else:
                year=int(year)
                result = convertToCanChi(year)
    return render_template("index.html", result=result, error=error, year=year)



