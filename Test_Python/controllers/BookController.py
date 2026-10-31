import logging, re,sys,pymysql
from flask import request,render_template
from models.BookModel import DB

class BookController:
    def index(self):
        success=""
        error=""
        book=None
        book_id=None
        db=None
        try: 
            db=DB()
            if request.method=='POST':
                book_id=request.method.get('book_id')
                if book_id==None:
                    error="Please require ID"
                else:
                    book_id=book_id.strip()
                    if book_id=="":
                        error="ID cannot be empty"
                    elif not re.fullmatch(r'[0-9]+',book_id):
                        error="ID must be a number"
                    else:
                        book_id=book_id.lstrip('0')
                        if book_id=="" or len(book_id)>len(str(sys.maxsize)) or ((len(book_id)==len(str(sys.maxsize))) and book_id>str(sys.maxsize)):
                            error="ID must < maxsize system"
                        else:
                            book_id=int(book_id)
                            book=db.getBook(book_id)
                            if book is None:
                                error="Book not found"
            action=None
            action=request.method.get('action')
            if action=='update_status':
                if book is None:
                    if error=="":
                        error="Book not found gto update"
                elif book['status']=='available':
                    rowAffected=db.updateStatusBook(book['id'],'reserved',book['status'])
                    if rowAffected>0:
                        success="Update status complete"
                        book['status']='reserved'
                    else:
                        book=db.getBook(book['id'])
                        if book:
                            error="Book status has been changed."
                        else:
                            error="Book not found. It may be delete"
                elif book['status']=='reserved' or book['status']=='borrowed':
                    error=f"Status can not update. Current status: {book['status']}" 
                else:
                    error="Unknown status to update"
        except pymysql.MySQLError as e:
            logging.error("Database error: %s",e)
            error="Database connection failed"
            success=""
            book=None
            book_id=None
        finally:
            if db:
                db.close()
        render_template('book.html',success=success,error=error,book=book,book_id=book_id)

