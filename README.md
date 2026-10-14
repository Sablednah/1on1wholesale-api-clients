# 1on1 Wholesale API clients

Ready-made clients for the two integration points every [1on1 Wholesale](https://www.1on1wholesale.co.uk) trade account has:

| | What it does | Direction |
|---|---|---|
| **Order API** | Place an order by posting a small XML document | you → 1on1 |
| **Order Status & Tracking feed** | Read back status and tracking numbers for your orders as XML | 1on1 → you |

Each client is a single file with **no third-party dependencies**:

| Language | File | Requires |
|---|---|---|
| PHP 7.4+ | [`php/Net1on1Client.php`](php/Net1on1Client.php) | ext-curl, ext-simplexml, ext-json (standard) |
| Python 3.8+ | [`python/net1on1.py`](python/net1on1.py) | standard library only |
| Node.js 14+ | [`node/net1on1.js`](node/net1on1.js) | nothing (CommonJS, built-in `https`) |
| curl | [`curl/`](curl/) | bash + curl |
| Postman | [`postman/`](postman/) | import the collection, fill in the two variables |

Each language folder has an `example.*` showing the three things you will actually do: send an order, poll the feed, look an order up by your own reference.

The full reference, with your personal credentials filled in, is under **My Account → API Documentation** when logged in to the website. The essentials are repeated below.

---

## Credentials

| | Value |
|---|---|
| **ID** | the email address you log in to 1on1wholesale.co.uk with |
| **API key** | shown under My Account → API Documentation. It is derived from your email, so it changes if your account email changes. |

All three clients derive the key for you if you only give them the email, but you can pass it explicitly.

Treat the key like a password: it can place orders on your account and read your order history.

## EU site (1on1wholesale.eu)

The same API runs on our EU site, with its own accounts, keys and endpoints. The clients default to the UK endpoints; point them at the EU ones when you create the client:

```php
$client = new Net1on1Client('you@yourshop.example', null, [
    'order_endpoint'  => 'https://www.1on1wholesale.eu/API/order/internal-import/index.php',
    'status_endpoint' => 'https://www.1on1wholesale.eu/order_status_xml.php',
]);
```
```python
client = Net1on1Client("you@yourshop.example",
    order_endpoint="https://www.1on1wholesale.eu/API/order/internal-import/index.php",
    status_endpoint="https://www.1on1wholesale.eu/order_status_xml.php")
```
```js
const client = new Net1on1Client('you@yourshop.example', null, {
  orderEndpoint:  'https://www.1on1wholesale.eu/API/order/internal-import/index.php',
  statusEndpoint: 'https://www.1on1wholesale.eu/order_status_xml.php',
});
```

What differs on the EU site:

| | UK (`.co.uk`) | EU (`.eu`) |
|---|---|---|
| Shipping | you pick a `shippingtype` (table below) | **automatic** - one service per destination country, `shippingtype` is ignored |
| Destinations | GB plus international rates | 30 European countries; others (incl. GB) are created without a shipping service and held |
| Currency | GBP | EUR |
| `street` | `line1\|line2` splits into two lines | single line, no split |
| Empty `telephone` / `email` | our office details substituted | stored empty |
| Status feed window | 14 days; `week=1`, `full=1`, `file=1` | **28 days**; only `full=1` (`week` and `file` are ignored) |
| Multi-parcel orders in the feed | first parcel only | one `tracking`/`shiptype`/`carrier` group per parcel |
| Carriers seen | Evri, DPDGroup, RoyalMail, UPS Netherlands | gls / GLS, DSV, UPS, Fedex |
| Support | sales@1on1wholesale.co.uk | sales@1on1wholesale.eu |

The destination table with charges, and everything else that is EU-specific, is under **My Account → API Documentation** on 1on1wholesale.eu. Everything not listed above (XML structure, response format, error messages, `orderno` uniqueness, all-or-nothing stock rule) is identical.

**Every request needs an HTTP `User-Agent` header.** Requests with none at all are rejected by the firewall with HTTP 403 before reaching the API. The clients set one; if you roll your own, send anything descriptive (`MyShop/1.0`).

---

## Quick start

### PHP

```php
require 'Net1on1Client.php';

$client = new Net1on1Client('you@yourshop.example');   // or ($email, $apiKey)

$result = $client->sendOrder([
    'orderno'  => 'SHOP-10001',                         // your reference, unique per account
    'shipping' => Net1on1Client::SHIP_TRACKED24,        // GB only; see Shipping types
    'address'  => [
        'firstname' => 'Jane', 'lastname' => 'Smith',
        'street'    => '12 High Street', 'street2' => 'Flat 3',
        'city'      => 'Manchester', 'region' => 'Greater Manchester',
        'postcode'  => 'M1 1AA', 'country' => 'GB',
        'telephone' => '07700900123', 'email' => 'jane@example.com',
    ],
    'items' => [
        ['sku' => 'N13125', 'quantity' => 1],
        ['sku' => 'N12946', 'variation' => 'NS8102', 'quantity' => 2],
    ],
]);
// $result['success'], $result['order_id'], $result['lines'][...]{sku, ok, status}

foreach ($client->getOrders(Net1on1Client::WINDOW_WEEK) as $o) {
    echo $o['orderref'], ' ', $o['status'], ' ', $o['tracking'] ?? '', PHP_EOL;
}
```

### Python

```python
from net1on1 import Net1on1Client, Order, Address, Item, SHIP_TRACKED24, WINDOW_WEEK

client = Net1on1Client("you@yourshop.example")        # or (email, api_key)

result = client.send_order(Order(
    orderno="SHOP-10001",
    shipping=SHIP_TRACKED24,
    address=Address(firstname="Jane", lastname="Smith", street="12 High Street", street2="Flat 3",
                    city="Manchester", region="Greater Manchester", postcode="M1 1AA", country="GB",
                    telephone="07700900123", email="jane@example.com"),
    items=[Item("N13125", 1), Item("N12946", 2, variation="NS8102")],
))
# result.success, result.order_id, result.lines[...] (sku, ok, status)

for o in client.get_orders(WINDOW_WEEK):
    print(o.orderref, o.status, o.tracking, o.tracking_url)
```

### Node.js

```js
const { Net1on1Client, SHIP, WINDOW } = require('./net1on1');

const client = new Net1on1Client('you@yourshop.example');   // or (email, apiKey)

const result = await client.sendOrder({
  orderno: 'SHOP-10001',
  shipping: SHIP.TRACKED24,
  address: { firstname: 'Jane', lastname: 'Smith', street: '12 High Street', street2: 'Flat 3',
             city: 'Manchester', region: 'Greater Manchester', postcode: 'M1 1AA', country: 'GB',
             telephone: '07700900123', email: 'jane@example.com' },
  items: [{ sku: 'N13125', quantity: 1 }, { sku: 'N12946', variation: 'NS8102', quantity: 2 }],
});
// result.success, result.orderId, result.lines[...] {sku, ok, status}

for (const o of await client.getOrders(WINDOW.WEEK)) console.log(o.orderref, o.status, o.tracking);
```

### curl

```sh
# send an order (XML in a file - see samples/order.xml)
curl -A "MyShop/1.0" --data-urlencode "xmlfeed@order.xml" \
     https://www.1on1wholesale.co.uk/API/order/internal-import/index.php

# status feed, last 7 days
curl -A "MyShop/1.0" "https://www.1on1wholesale.co.uk/order_status_xml.php?email=YOU&key=KEY&week=1"
```

---

## Order API

| | |
|---|---|
| Endpoint | `https://www.1on1wholesale.co.uk/API/order/internal-import/index.php` |
| Method | `POST` (form-encoded). `GET` works for small orders if the XML is URL-encoded. |
| Body | one form field, **`xmlfeed`**, containing the XML. Not raw XML in the body. |
| Response | always **HTTP 200**. Body is a JSON array on success or plain text beginning `Fatal error:` on rejection. |

### XML

```xml
<?xml version="1.0" encoding="UTF-8"?>
<order>
  <dropshipper><auth><id>you@yourshop.example</id><key>YOUR-KEY</key></auth></dropshipper>
  <orderno>SHOP-10001</orderno>
  <shippingaddress>
    <firstname>Jane</firstname>
    <lastname>Smith</lastname>
    <street>12 High Street|Flat 3</street>
    <city>Manchester</city>
    <region>Greater Manchester</region>
    <postcode>M1 1AA</postcode>
    <country_id>GB</country_id>
    <telephone>07700900123</telephone>
    <email>jane@example.com</email>
  </shippingaddress>
  <orderitems>
    <orderitem><product>N13125</product><childproduct/><quantity>1</quantity></orderitem>
    <orderitem><product>N12946</product><childproduct>NS8102</childproduct><quantity>2</quantity></orderitem>
  </orderitems>
  <shipping><shippingtype>TRACKED24</shippingtype></shipping>
</order>
```

| Element | Required | Notes |
|---|---|---|
| `dropshipper/auth/id` | yes | your account email |
| `dropshipper/auth/key` | yes | your API key |
| `orderno` | yes | your reference. **Unique per account** - a repeat is rejected as a duplicate. Returned as `importorderref` in the feed. |
| `shippingaddress/firstname`, `lastname` | yes | recipient |
| `shippingaddress/street` | yes | use `\|` to separate two address lines. No company field - put it in the street if needed. |
| `shippingaddress/city` | yes | |
| `shippingaddress/postcode` | yes | also decides Highlands & Islands / Channel Islands carrier for GB |
| `shippingaddress/country_id` | yes | ISO 3166-1 alpha-2 (`GB`, `IE`, `DE`, `US`). |
| `shippingaddress/region` | no | county / state |
| `shippingaddress/telephone` | recommended | courier notifications; a placeholder is substituted if empty |
| `shippingaddress/email` | recommended | courier notifications; go to 1on1 if empty |
| `orderitems/orderitem` | yes, 1+ | |
| `orderitem/product` | yes | product SKU (`N…`) |
| `orderitem/childproduct` | for variants | variation SKU (`NS…`). **Required** for sized/coloured products; `<childproduct/>` otherwise. |
| `orderitem/quantity` | yes | integer ≥ 1 |
| `shipping/shippingtype` | no | see below. Ignored for non-GB. Omitted / unknown = standard. |
| `shipping/ignore_non_net1on1` | no | `1` = skip SKUs 1on1 does not stock instead of rejecting the order |

Prices are **not** read from the XML; lines are charged at your account's trade price. Billing details come from your account (a default billing address must be saved under My Account → Addresses).

### Shipping types (GB addresses)

| Value | Service | ex VAT |
|---|---|---|
| `STANDARD` (or anything unrecognised / empty) | Evri Standard (2-3 working days) | £3.40 |
| `TRACKED24` | Evri Next Day | £4.13 |
| `NEXTDAY` | DPD Next Working Day | £4.95 |
| `RM24` | Royal Mail Tracked 24 | £5.40 |
| `RM48` | Royal Mail Tracked 48 | £4.50 |
| `SATURDAY` | Evri Next Day (no dedicated Saturday service) | £4.13 |

Older aliases are still accepted, case-insensitively: `express`, `nextday`, `DPD Next Day`, `DHL Next Working Day` → `NEXTDAY`; `Evri Next Day`, `Tracked 24 Class Post`, `TRACKED 24`, `Tracked 24 Delivery` → `TRACKED24`; `Royal Mail Tracked 24`, `Royal Mail Tracked delivery 24` → `RM24`; `Royal Mail Tracked 48` → `RM48`; `Saturday Delivery`, `weekend` → `SATURDAY`.

Highlands & Islands and Channel Islands postcodes: `STANDARD` and `TRACKED24` are switched to **Royal Mail Tracked 48** (Evri does not deliver there).

Non-GB: `shippingtype` is ignored. EU (plus NO, CH, LI, MC, RS, BA) = "EU Shipping" £15.30; everywhere else = "Non UK" £25.40.

Charges are correct at time of writing; the order confirmation is authoritative.

### Response

Created:

```json
[
  {"sku": "N13125",  "error": 0, "productstatus": "In stock"},
  {"sku": "NS8102", "error": 0, "productstatus": "In stock"},
  {"orderid": "5148307"}
]
```

Not created (**no partial orders** - fix the failing line and resend with the same `orderno`):

```json
[
  {"sku": "N13125",  "error": 1, "productstatus": "out of stock"},
  {"sku": "NS8102", "error": 0, "productstatus": "In stock"},
  {"orderid": null}
]
```

`productstatus` is `In stock`, `out of stock`, `not available` (SKU unknown) or `variation required - send the variation (NS) SKU in childproduct` (variable product sent without a variation). `orderid` is the 1on1 order number - the `orderref` you will see in the status feed.

Rejected (plain text, still HTTP 200):

| Message | Meaning |
|---|---|
| `Fatal error: XML Missing or not found - is it posted as 'xmlfeed'?` | `xmlfeed` field empty/absent - you probably posted raw XML as the body |
| `Fatal error: XML invalid - check for closing tags, and special characters …` | XML did not parse - usually an unescaped `&` |
| `Fatal error: Account with email … not found.` | wrong `id` |
| `Fatal error: API Key incorect` | wrong `key` for that email |
| `Fatal error: Order items empty or missing` | no `<orderitem>` elements |
| `Fatal error: Duplicate order. Order … already processed.` | that `orderno` already exists on your account - nothing new was created. Look it up in the feed by `importorderref`. |
| `Fatal error: Order not created.` | save failed - retry once, then contact us with the XML |

The clients raise `Net1on1ApiException` / `Net1on1ApiError` for these, and a transport exception for network / HTTP failures. A created-vs-not-created result is **not** an exception: check `success` / `order_id`.

### Rules

- One order per request. `orderno` is your idempotency key - retrying after a timeout is safe.
- All-or-nothing: an out-of-stock line rejects the whole order.
- Unknown SKUs reject the order unless `ignore_non_net1on1` is `1`; an order where every line is unknown is rejected either way.
- New orders arrive as *Pending*. Accounts with credit terms move to *Processing* automatically; otherwise payment is processed on your usual terms first.
- Allow 60 s+ for a response.
- There is no sandbox flag - a valid request creates a real order. Test transport with a wrong key (expect `API Key incorect`) or an order containing an out-of-stock SKU (full validation, nothing created). Email us to arrange genuine test orders.

---

## Order Status & Tracking feed

| | |
|---|---|
| URL | `https://www.1on1wholesale.co.uk/order_status_xml.php?email=YOU&key=KEY` |
| Method | `GET` |
| Response | `application/xml` |

| Parameter | Effect |
|---|---|
| *(none)* | orders **created** in the last 14 days |
| `week=1` | last 7 days - use this for polling |
| `full=1` | every order, no date limit - slow; initial import only |
| `file=1` | serve as a download named `orders.xml` |

> **A wrong email or key is not an error** - the feed returns an empty `<ordereport/>`. Empty result = check credentials first, then the date window.

The feed includes **all** orders on the account (website, CSV upload, API). Only API orders carry `importorderref`.

```xml
<?xml version="1.0"?>
<ordereport>
  <order>
    <orderref>5148307</orderref>
    <status>complete</status>
    <importorderref>SHOP-10001</importorderref>
    <tracking>H07FAA0000274939</tracking>
    <shiptype>Evri</shiptype>
    <carrier>Evri</carrier>
    <subtotal>6.96</subtotal>
    <grand_total>12.43</grand_total>
    <shipping_amount>3.4</shipping_amount>
    <tax_amount>2.07</tax_amount>
    <total_item_count>1</total_item_count>
    <customer_email>you@yourshop.example</customer_email>
    <customer_firstname>Jane</customer_firstname>
    <customer_lastname>Smith</customer_lastname>
    <created_at>2026-10-08T20:43:20+01:00</created_at>
    <updated_at>2026-10-09T09:12:01+01:00</updated_at>
  </order>
</ordereport>
```

| Element | Notes |
|---|---|
| `orderref` | 1on1 order number (= `orderid` from the Order API) |
| `status` | `pending`, `on-hold`, `processing`, `complete` (sic), `cancelled`, `refunded`, `failed`. Treat anything else as in-progress. |
| `importorderref` | your `orderno`; **API orders only** |
| `tracking` | **present only once shipped**; first parcel only |
| `shiptype` / `carrier` | carrier name as recorded by the warehouse: `Evri`, `DPDGroup`, `RoyalMail`, `UPS Netherlands`, `Shipping` (unknown) … match case-insensitively and fall back gracefully |
| `subtotal`, `grand_total`, `shipping_amount`, `tax_amount` | plain decimals, no fixed precision |
| `total_item_count` | units across all lines |
| `customer_email` | your account's billing email |
| `customer_firstname` / `customer_lastname` | the **recipient's** name |
| `created_at` / `updated_at` | ISO 8601 with UK offset |

Tracking pages (the clients expose this as `trackingUrl()` / `tracking_url`):

| shiptype | URL |
|---|---|
| Evri | `https://www.evri.com/track/parcel/{tracking}` |
| DPDGroup | `https://www.dpd.co.uk/apps/tracking/?reference={tracking}` |
| RoyalMail | `https://www.royalmail.com/track-your-item#/tracking-results/{tracking}` |
| UPS Netherlands | `https://www.ups.com/track?tracknum={tracking}` |

**Polling:** `week=1` every 15-60 minutes is plenty - tracking is written when the warehouse despatches, usually once per working day. Keep your own list of open orders; match API orders on `importorderref`, others on `orderref`. The window is by *creation* date, so an order placed 20 days ago and shipped today will not be in the default window.

---

## Samples

- [`samples/order.xml`](samples/order.xml) - a complete order
- [`samples/order-response-success.json`](samples/order-response-success.json), [`samples/order-response-failed.json`](samples/order-response-failed.json)
- [`samples/order-status-feed.xml`](samples/order-status-feed.xml) - a feed with processing and shipped orders across three carriers

## Support

Email [sales@1on1wholesale.co.uk](mailto:sales@1on1wholesale.co.uk) with the XML you sent and the exact response you received.

## Licence

MIT - see [LICENSE](LICENSE).
