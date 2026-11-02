import pymysql

class DB:
    def __init__(self):
        self.connection=pymysql.connect(
            host='localhost',
            db="DB",
            user="root",
            password="123",
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor
        )
    def getBook(self,book_id):
        with self.connection.cursor() as cursor:
            sql="SELECT * from books where id=%s"
            cursor.execute(sql,[book_id])
            result=cursor.fetchone()
            return result


    def updateStatusBook(self,book_id,new_status,old_status):
        with self.connection.cursor() as cursor:
            sql="UPDATE books SET status=%s WHERE id=%s and status=%s"
            cursor.execute(sql,[new_status,book_id,old_status])
            rowAffected=cursor.rowcount
            self.connection.commit()
            return rowAffected

    def close(self):
        self.connection.close()

        



