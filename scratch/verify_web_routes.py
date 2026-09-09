import urllib.request
import urllib.parse
import http.cookiejar
import re

cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

# 1. Fetch login page to get CSRF token
login_url = "http://localhost/assetvault/login.php"
try:
    resp = opener.open(login_url)
    html = resp.read().decode('utf-8')
    match = re.search(r'name="csrf_token"\s+value="([^"]+)"', html)
    if not match:
        print("CSRF token not found in login page")
        exit(1)
    csrf_token = match.group(1)
    print("Found CSRF token:", csrf_token[:10] + "...")

    # 2. Perform Login
    login_data = urllib.parse.urlencode({
        'csrf_token': csrf_token,
        'identifier': 'admin',
        'password': 'Admin@123456'
    }).encode('utf-8')

    req = urllib.request.Request(login_url, data=login_data, method='POST')
    login_resp = opener.open(req)
    print("Login status code:", login_resp.getcode())

    # 3. Test Routes
    routes = [
        ("Dashboard", "http://localhost/assetvault/dashboard"),
        ("Catalog Directory", "http://localhost/assetvault/catalog"),
        ("Inventory Stock Ledger", "http://localhost/assetvault/inventory"),
        ("Checkout Page", "http://localhost/assetvault/assignments/checkout"),
        ("Assignments Directory", "http://localhost/assetvault/assignments"),
        ("Reports Hub", "http://localhost/assetvault/reports"),
        ("Staff Directory", "http://localhost/assetvault/staff")
    ]

    for name, url in routes:
        page_resp = opener.open(url)
        page_html = page_resp.read().decode('utf-8')
        print(f"[OK] {name} ({url}) -> Status: {page_resp.getcode()}, Length: {len(page_html)} bytes")
        # Check for php fatal errors
        if "Fatal error" in page_html or "Parse error" in page_html:
            print(f"  [ERROR] Fatal/Parse error detected in {name}!")
        else:
            print(f"  [SUCCESS] Clean render: {name}")

except Exception as e:
    print("HTTP test failed:", e)
