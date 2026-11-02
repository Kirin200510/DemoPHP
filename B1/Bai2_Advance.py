import re

def processSentence(sentence):
    sentence=sentence.strip()
    # loại khoang trắng thừa giữa các từ 
    sentence = ' '.join(sentence.split())
    if sentence=="":
        return ""
    # chuẩn hóa dấu câu 
    sentence=re.sub(r'\s*([.,!?])\s*', r'\1 ', sentence)
    # tách câu thành các từ
    words=sentence.split(" ")
    # đảo ngược các từ trong câu
    words.reverse()
    # nối các từ lại thành câu mới
    new_sentence=" ".join(words)
    # in hoa chữ cái đầu của câu
    new_sentence=new_sentence.lower()
    new_sentence=new_sentence.strip()
    new_sentence=new_sentence.capitalize()


    return new_sentence

print (processSentence("  Học python rất thú vị!"))
    


