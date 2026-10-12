from net1on1 import (Net1on1Client, Order, Address, Item, Net1on1ApiError,
                     Net1on1TransportError, SHIP_TRACKED24, WINDOW_WEEK)

# ID = the email you log in to 1on1wholesale.co.uk with.
# Key = shown under My Account -> API Documentation (or omit it and it is derived).
client = Net1on1Client("you@yourshop.example", "your-api-key-here")

# ---- 1. Send an order -------------------------------------------------------
order = Order(
    orderno="SHOP-10001",                 # your reference - must be unique
    shipping=SHIP_TRACKED24,
    address=Address(
        firstname="Jane", lastname="Smith",
        street="12 High Street", street2="Flat 3",
        city="Manchester", region="Greater Manchester",
        postcode="M1 1AA", country="GB",
        telephone="07700900123", email="jane.smith@example.com",
    ),
    items=[
        Item(sku="N13125", quantity=1),
        Item(sku="N12946", variation="NS8102", quantity=2),
    ],
)

try:
    result = client.send_order(order)
    if result.success:
        print("Order created: 1on1 order number", result.order_id)
    else:
        print("Order NOT created. Problem lines:")
        for line in result.lines:
            if not line.ok:
                print(f"  {line.sku}: {line.status}")
except Net1on1ApiError as e:       # bad key, duplicate orderno, invalid XML ...
    print("Rejected:", e)
except Net1on1TransportError as e:
    print("Network problem:", e)

# ---- 2. Poll the status feed -------------------------------------------------
for o in client.get_orders(WINDOW_WEEK):
    print(o.orderref, o.status, o.importorderref or "-", o.shiptype or "", o.tracking or "",
          o.tracking_url or "")

# ---- 3. Look up one order by your own reference ------------------------------
o = client.find_order_by_ref("SHOP-10001")
print(f"SHOP-10001 is 1on1 order {o.orderref} ({o.status})" if o else "SHOP-10001 not found in window")
