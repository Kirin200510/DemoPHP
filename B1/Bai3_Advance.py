import re
#  kiểm tra chuỗi có chứa chuỗi con palindrome hay không
def has_palindrome_in_string(s):
    s=s.strip()
    # loại bỏ ký tự khoảng trắng, dấu câu và ký tự đặc biệt
    s=re.sub(r'[^a-zA-Z0-9]', '', s)
    # split từng ký tự
    words_s=list(s)
    words_reversed=list(s)
    words_reversed.reverse()
    length=len(s)
    for i in range(length):
        for j in range(i + 1, length + 1):
            if words_s[i]==words_reversed[j] and words_s[j]==words_reversed[i]:
                return True
    return False

def longest_palindrome(s):
    s=s.strip()
    # loại bỏ ký tự khoảng trắng, dấu câu và ký tự đặc biệt
    s=re.sub(r'[^a-zA-Z0-9]', '', s)
    print(s)
    length=len(s)
    longest_palindrome=''
    for i in range(length):
        for j in range(i + 1, length + 1):
            substring=s[i:j]
            if substring==substring[::-1] and len(substring)>len(longest_palindrome):
                longest_palindrome=substring
    return longest_palindrome

                
    

print(has_palindrome_in_string("xyz-levavavel-xyz"))
print(has_palindrome_in_string("python is fun"))
if has_palindrome_in_string("abc_xyz-levavavel-xyz"):
    print("Câu vừa nhập có chứa Palindrome")
    print("Palindrome dài nhất trong câu là: " + longest_palindrome("abc_xyz-levavavel-xyz"))