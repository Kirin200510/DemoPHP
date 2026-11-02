import re
def is_palindrome(s):
    s=s.strip()
    s=''.join(s.split())
    # split từng ký tự
    words=list(s)
    words.reverse()
    new_s=''.join(words)
    if new_s.lower()==s.lower():
        return True
    else:
        return False

print(is_palindrome("tôi nan Iôt"))

