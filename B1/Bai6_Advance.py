def climbingStairWithMemo(n, memo={}):
    if n < 0: return 0
    if n in memo:
        return memo[n]
    if n <= 2 and n > 0:
        return n
    memo[n] = climbingStairWithMemo(n - 1, memo) + climbingStairWithMemo(n - 2, memo)
    return memo[n]
print (climbingStairWithMemo(45))