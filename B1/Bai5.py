def countNumberTimes(arr):
    count_dict = {}
    for num in arr:
        if num in count_dict:
            count_dict[num] += 1
        else:
            count_dict[num] = 1
    return count_dict

def showNumberHighestTimes(arr):
    count_dict = countNumberTimes(arr)
    max_count = max(count_dict.values())
    highest_numbers = [num for num, count in count_dict.items() if count == max_count]
    return highest_numbers, max_count

print(showNumberHighestTimes([3, 1, 4, 1, 5, 9, 2, 6, 5, 3, 5,3]))