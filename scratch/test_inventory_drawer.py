import urllib.request
import urllib.parse
import http.cookiejar
import re

cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

# 1. Login
login_url = "http://localhost/assetvault/login.php"
resp = opener.open(login_url)
html = resp.read().decode('utf-8')
csrf_token = re.search(r'name="csrf_token"\s+value="([^"]+)"', html).group(1)

login_data = urllib.parse.urlencode({
    'csrf_token': csrf_token,
    'identifier': 'admin',
    'password': 'Admin@123456'
}).encode('utf-8')
opener.open(login_url, data=login_data)

# 2. Fetch inventory page
inv_resp = opener.open("http://localhost/assetvault/inventory")
inv_html = inv_resp.read().decode('utf-8')

# Check options in rcv_product_id
select_match = re.search(r'<select[^>]+id="rcv_product_id"[^>]*>(.*?)</select>', inv_html, re.DOTALL)
if select_match:
    options = re.findall(r'<option\s+value="(\d+)"[^>]*>(.*?)</option>', select_match.group(1))
    print(f"Found {len(options)} bulk products in dropdown:")
    for opt_id, opt_text in options:
        print(f"  - [{opt_id}] {opt_text.strip()}")
else:
    print("Select rcv_product_id not found!")

# 3. Test submitting a stock intake batch
csrf_token_inv = re.search(r'name="csrf_token"\s+value="([^"]+)"', inv_html).group(1)
post_data = urllib.parse.urlencode({
    'action': 'receive_stock',
    'csrf_token': csrf_token_inv,
    'product_id': '21', # Logitech B100 Mouse
    'location_id': '1', # IT Store
    'quantity': '50',
    'unit_cost': '6500.00',
    'received_date': '2026-09-09',
    'supplier_name': 'MegaTech Distributors Nigeria',
    'reference_no': 'PO-2026-0881',
    'batch_number': 'BATCH-MOU-01',
    'notes': 'Intact packaging, 50 sealed units verified.'
}).encode('utf-8')

post_resp = opener.open("http://localhost/assetvault/inventory", data=post_data)
post_html = post_resp.read().decode('utf-8')

if "Stock receipt of 50 units recorded successfully" in post_html:
    print("[SUCCESS] Successfully received 50 units into stock ledger!")
else:
    print("[FAIL] Stock receipt message not found!")

# 4. Check checkout page dropdown now
co_resp = opener.open("http://localhost/assetvault/assignments/checkout")
co_html = co_resp.read().decode('utf-8')
if "Logitech B100 Optical USB Mouse" in co_html and "50 units available" in co_html:
    print("[SUCCESS] Bulk product and stock level correctly appear in Staff Checkout wizard!")
else:
    print("[NOTICE] Checkout html check...")
