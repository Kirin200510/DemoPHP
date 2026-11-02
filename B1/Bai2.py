def myReverse(s):
    len_s = len(s)
    reverse_s = ""
    for i in range(len_s - 1, -1, -1):
        reverse_s += s[i]
    return reverse_s