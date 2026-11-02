def flattenNumberArray(arr):
    flat_list = []
    for item in arr:
        if isinstance(item, list):
            flat_list.extend(flattenNumberArray(item))
        else:
            flat_list.append(item)
    flat_list=[int(x) for x in flat_list if isinstance(x, (int, float, str)) and str(x).isdigit()]
    return flat_list

def countNumberTimes(arr):
    count_dict = {}
    for num in arr:
        if num in count_dict:
            count_dict[num] += 1
        else:
            count_dict[num] = 1
    return count_dict

def topKCountNumberTimes(arr, k):
    count_dict = countNumberTimes(arr)
    sorted_counts = sorted(count_dict.items(), key=lambda x: x[1], reverse=True)
    top_k = sorted_counts[:k]
    return top_k

input= [
    [3, 1, 4],
    [1, 5, [9, 2]],
    [6, 5, 3, 5],
    "3", "1", "4"]

flattenedArray = flattenNumberArray(input)
print("Mảng sau khi làm phẳng:", flattenedArray)

countedNumbers = countNumberTimes(flattenedArray)
print("Số lần xuất hiện của từng số trong mảng:", countedNumbers)

top4 = topKCountNumberTimes(flattenedArray, 4)
print("Top 4 số xuất hiện nhiều nhất:", top4)