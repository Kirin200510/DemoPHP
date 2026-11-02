
def fizz_buzz_bang(n):
    for i in range(1, n + 1):
        output = ""
        if i % 3 == 0:
            if i % 5 == 0:
                if i % 7 == 0:
                    output += "FizzBuzzBang"
                else:
                    output += "FizzBuzz"
            elif i % 7 == 0:
                output += "FizzBang"
            else:
                output += "Fizz"
        if i % 5 == 0 and i % 3 != 0:
            if i % 7 == 0:
                output += "BuzzBang"
            else:
                output += "Buzz"
        if i % 7 == 0 and i % 3 != 0 and i % 5 != 0:
            output += "Bang"
        
        print(output or i)

print("Enter a number N:")
try:
    N = int(input())
    fizz_buzz_bang(N)
except ValueError:
    print("Please enter a valid integer.")
