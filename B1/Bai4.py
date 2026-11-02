def is_Prime(n):
    if n < 2:
        return False
    for i in range(2, int(n**0.5) + 1):
        if n % i == 0:
            return False
    return True

def showPrime(n):
    primes = []
    for i in range(2, n + 1):
        if is_Prime(i):
            primes.append(i)
    return primes

print(showPrime(50))