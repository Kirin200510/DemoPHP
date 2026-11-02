import math
def isPrime(n):
    if n < 2:
        return False
    for i in range(2, int(n**0.5) + 1):
        if n % i == 0:
            return False
    return True

def FooBarBazPrime(N):
    for i in range(1, N + 1):
        output = ''
        if i % 2 == 0:
            output += 'Foo'
        if i % 3 == 0:
            output += 'Bar'
        if i % 5 == 0:
            output += 'Baz'
        if isPrime(i):
            output += 'Prime'
        if output == '':
            output = str(i)
        print(output)
        print(" ")

print("Enter a number N:")
try:
    N = int(input())
    FooBarBazPrime(N)
except ValueError:
    print("Please enter a valid integer.")
    