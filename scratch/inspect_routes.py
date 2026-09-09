import urllib.request
import urllib.parse
import http.cookiejar
import re

cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

# 1. Fetch login page
login_url = "http://localhost/assetvault/login.php"
resp = opener.open(login_url)
html = resp.read().decode('utf-8')
match = re.search(r'name="csrf_token"\s+value="([^"]+)"', html)
csrf_token = match.group(1)

# 2. Login
login_data = urllib.parse.urlencode({
    'csrf_token': csrf_token,
    'identifier': 'admin',
    'password': 'Admin@123456'
}).encode('utf-8')

login_resp = opener.open(login_url, data=login_data)
final_url = login_resp.geturl()
print("After login URL:", final_url)

# Test Catalog page
cat_resp = opener.open("http://localhost/assetvault/catalog")
cat_html = cat_resp.read().decode('utf-8')
title_match = re.search(r'<title>(.*?)</title>', cat_html)
print("Catalog URL:", cat_resp.geturl())
print("Catalog Title:", title_match.group(1) if title_match else "No title")

# Test Inventory page
inv_resp = opener.open("http://localhost/assetvault/inventory")
inv_html = inv_resp.read().decode('utf-8')
title_match = re.search(r'<title>(.*?)</title>', inv_html)
print("Inventory URL:", inv_resp.geturl())
print("Inventory Title:", title_match.group(1) if title_match else "No title")
