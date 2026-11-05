import re, sys
from flask import request, render_template

class Test:
    def validateRange(self, value):
        value=value.strip()
        value=value.lstrip('0')
        if value=='' or len(value)>len(str(sys.maxsize)) or (len(value)==len(str(sys.maxsize)) and value>str(sys.maxsize)):
            return False
        else:
            value=int(value)
            return value>=1
    def validateInput(self, input):
        input=input.strip()
        if input=="":
            return "Input cannot be empty"
        if re.match(r'^[0-9]+[.][0-9]+$', input):
            return "Năm phải là số nguyên"
        # nhập 1 năm
        elif re.match(r'^[0-9]+$', input):
            if not self.validateRange(input):
                return "Năm phải nằm trong khoảng từ 1 đến " + str(sys.maxsize)
            else:
                return "Valid input"
        # hàm chuỗi tồn tại ký tự tên là  
        elif ',' in input:
            years=input.split(',')
            if len(years)>20:
                return "Số lượng năm không được vượt quá 20"
            for year in years:
                year=year.strip()
                if not re.match(r'^[0-9]+$', year):
                    return "Phần tử năm không hợp lệ"
                elif not self.validateRange(year):
                    return "Năm phải nằm trong khoảng từ 1 đến " + str(sys.maxsize)
            return "Valid input"
        elif '-' in input:
            if re.match(r'^[-][0-9]+$', input):
                return "Năm không được âm"
            years=input.split('-')
            if len(years)!=2:
                return "Phần tử không hợp lệ"
            start_year=years[0].strip()
            end_year=years[1].strip()
            if not re.match(r'^[0-9]+$', start_year) or not re.match(r'^[0-9]+$', end_year):
                return "Khoảng năm không hợp lệ"
            elif not self.validateRange(start_year) or not self.validateRange(end_year):
                return "Năm phải nằm trong khoảng từ 1 đến " + str(sys.maxsize)
            elif int(start_year.lstrip('0'))>int(end_year.lstrip('0')):
                return "Năm bắt đầu phải nhỏ hơn hoặc bằng năm kết thúc"
            elif int(end_year.lstrip('0'))-int(start_year.lstrip('0'))>100:
                return "Khoảng năm không được vượt quá 100 năm"
            else:
                return "Valid input"
        else:
            return "Dữ liệu không hợp lệ"
    
    def parseInput(self, input):
        years=[]
        input=input.strip()
        if re.match(r'^[0-9]+$', input):
            years=[int(input.lstrip('0'))]
        elif ',' in input:
            results=input.split(',')
            years=[int(year.strip().lstrip('0')) for year in results]
        elif '-' in input:
            results=input.split('-')
            start_year=int(results[0].strip().lstrip('0'))
            end_year=int(results[1].strip().lstrip('0'))
            years=list(range(start_year, end_year+1))
        else:
            return []
        return years
    
    def convertToCanChi(self, year):
        can=["Giáp", "Ất", "Bính", "Đinh", "Mậu", "Kỷ", "Canh", "Tân", "Nhâm", "Quý"]
        chi=["Tý", "Sửu", "Dần", "Mão", "Thìn", "Tỵ", "Ngọ", "Mùi", "Thân", "Dậu", "Tuất", "Hợi"]
        can_index=((year-1984)%10+10)%10
        chi_index=((year-1984)%12+12)%12
        return can[can_index]+" "+chi[chi_index]
    
    def processYears(self, years):
        results=[]
        for year in years:
            can_chi=self.convertToCanChi(year)
            results.append({"year": year, "can_chi": can_chi})
        return results
    
    def getStatistic(self, years):
        totalYears=len(years)
        # Số năm duy nhất
        uniqueYears=len(set(years))
        duplicateYears=totalYears-uniqueYears
        return {
            "total_years": totalYears,
            "unique_years": uniqueYears, 
            "duplicate_years": duplicateYears
        }
    
    def findSameCanChi(self, years, nam):
        results=[]
        for year in years:
            if (nam-year)%60==0:
                results.append(year)
        return results
    
    def index(self):
        error=""
        validationMessage=""
        validationNamMessage=""
        results=[]
        input=""
        nam=""
        statistic={}
        sameCanChiYears=[]
        years=[]
        if request.method=="POST":
            input=request.form.get("input", "")
            nam=request.form.get("nam", "")
            validationMessage=self.validateInput(input)
            validationNamMessage=self.validateInput(nam)
            if validationMessage=="Valid input" and validationNamMessage=="Valid input":
                years=self.parseInput(input)
                results=self.processYears(years)
                statistic=self.getStatistic(years)
                sameCanChiYears=self.findSameCanChi(years, int(nam.lstrip('0')))
            else:
                error="Input 1: "+validationMessage+"; Input 2: "+validationNamMessage
        return render_template("index.html", error=error, results=results, input=input, nam=nam, statistic=statistic, sameCanChiYears=sameCanChiYears)



               
