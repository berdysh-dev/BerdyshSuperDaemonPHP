#! /bin/python

import cgi

form = cgi.FieldStorage()

v1 = form.getfirst('v')
v2 = form.getfirst('v2')

print("Content-Type: text/html")
print()


