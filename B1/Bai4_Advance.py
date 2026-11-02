def eratosthenes(n):
    is_prime=[True] * (n + 1)
    is_prime[0]=is_prime[1]=False
    for i in range(2, int(n**0.5) + 1):
        if is_prime[i]:
            for j in range(i * i, n + 1, i):
                is_prime[j]=False
    return [i for i in range(n + 1) if is_prime[i]]

def primeFactor(n):
    number_prime=eratosthenes(n)
    factors=[]
    for i in range(len(number_prime)):
        if number_prime[i] and n%number_prime[i]==0:
            count=0
            while(n%number_prime[i]==0):
                n//=number_prime[i]
                count+=1
            factors.append((number_prime[i], count))
    return factors

print("Nhập số nguyên dương n: ")
n=int(input())
primes=eratosthenes(n)
factors=primeFactor(n)
print("Các số nguyên tố nhỏ hơn hoặc bằng", n, "là:", primes)
print("Phân tích số", n, "thành các thừa số nguyên tố là:")
for i in range(len(factors)):
    if i>0:
        print(" *", end=" ")
    print(factors[i][0], "^", factors[i][1],end="")
print("\n")
