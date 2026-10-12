"""
1on1 Wholesale API client - Python 3.8+.

Standard library only (urllib, xml.etree, json, hashlib).

    from net1on1 import Net1on1Client, Order, Address, Item
    client = Net1on1Client("you@yourshop.example")          # key derived automatically
    result = client.send_order(Order(...))                   # see example.py
    for o in client.get_orders(window="week"): ...

Full field reference: My Account -> API Documentation on 1on1wholesale.co.uk

License: MIT
"""
from __future__ import annotations

import hashlib
import json
import re
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from dataclasses import dataclass, field
from typing import List, Optional
from xml.sax.saxutils import escape

ORDER_ENDPOINT = "https://www.1on1wholesale.co.uk/API/order/internal-import/index.php"
STATUS_ENDPOINT = "https://www.1on1wholesale.co.uk/order_status_xml.php"

# Shipping types for GB addresses. Anything else = STANDARD.
SHIP_STANDARD = "STANDARD"
SHIP_TRACKED24 = "TRACKED24"
SHIP_NEXTDAY = "NEXTDAY"
SHIP_RM24 = "RM24"
SHIP_RM48 = "RM48"
SHIP_SATURDAY = "SATURDAY"

# Status feed windows
WINDOW_DEFAULT = "default"  # last 14 days
WINDOW_WEEK = "week"        # last 7 days
WINDOW_FULL = "full"        # everything - slow, initial import only


class Net1on1Error(Exception):
    """Base class."""


class Net1on1ApiError(Net1on1Error):
    """The API answered but rejected the request (bad key, duplicate order, invalid XML...)."""


class Net1on1TransportError(Net1on1Error):
    """Network / HTTP failure (timeout, DNS, firewall 403)."""


@dataclass
class Address:
    firstname: str
    lastname: str
    street: str
    city: str
    postcode: str
    country: str = "GB"          # ISO 3166-1 alpha-2
    street2: str = ""            # optional second line
    region: str = ""             # county / state, optional
    telephone: str = ""          # optional, recommended (courier notifications)
    email: str = ""              # optional, recommended (courier notifications)


@dataclass
class Item:
    sku: str                     # N... product SKU
    quantity: int = 1
    variation: str = ""          # NS... variation SKU, required for sized/coloured products


@dataclass
class Order:
    orderno: str                 # your reference - must be unique per account
    address: Address
    items: List[Item]
    shipping: str = SHIP_STANDARD
    ignore_unknown_skus: bool = False


@dataclass
class LineResult:
    sku: str
    ok: bool
    status: str                  # 'In stock' | 'out of stock' | 'not available'


@dataclass
class OrderResult:
    success: bool                # True when an order was created
    order_id: Optional[str]      # 1on1 order number (= <orderref> in the status feed)
    lines: List[LineResult]
    raw: str


@dataclass
class OrderStatus:
    orderref: str
    status: str
    importorderref: Optional[str] = None   # your orderno - API orders only
    tracking: Optional[str] = None         # present once shipped
    shiptype: Optional[str] = None
    carrier: Optional[str] = None
    subtotal: float = 0.0
    grand_total: float = 0.0
    shipping_amount: float = 0.0
    tax_amount: float = 0.0
    total_item_count: int = 0
    customer_email: str = ""
    customer_firstname: str = ""
    customer_lastname: str = ""
    created_at: str = ""
    updated_at: str = ""

    @property
    def tracking_url(self) -> Optional[str]:
        return tracking_url(self.shiptype, self.tracking)


def key_for_email(email: str) -> str:
    """The API key is the MD5 of the account email."""
    return hashlib.md5(email.strip().encode("utf-8")).hexdigest()


def tracking_url(shiptype: Optional[str], tracking: Optional[str]) -> Optional[str]:
    """Public tracking page for a feed row, or None if the carrier is unknown."""
    if not tracking:
        return None
    t = urllib.parse.quote(tracking, safe="")
    s = (shiptype or "").replace(" ", "").lower()
    if s == "evri":
        return f"https://www.evri.com/track/parcel/{t}"
    if s in ("dpdgroup", "dpd", "dpd.co.uk"):
        return f"https://www.dpd.co.uk/apps/tracking/?reference={t}"
    if s == "royalmail":
        return f"https://www.royalmail.com/track-your-item#/tracking-results/{t}"
    if s in ("upsnetherlands", "ups"):
        return f"https://www.ups.com/track?tracknum={t}"
    return None


class Net1on1Client:
    def __init__(
        self,
        email: str,
        api_key: Optional[str] = None,
        *,
        user_agent: str = "Net1on1Client-Python/1.0",
        timeout: int = 90,
        order_endpoint: str = ORDER_ENDPOINT,
        status_endpoint: str = STATUS_ENDPOINT,
    ):
        self.email = email.strip()
        self.api_key = api_key or key_for_email(self.email)
        self.user_agent = user_agent  # required - an empty User-Agent is firewalled
        self.timeout = timeout
        self.order_endpoint = order_endpoint
        self.status_endpoint = status_endpoint

    # ---------------------------------------------------------------- orders

    def build_order_xml(self, order: Order) -> str:
        if not order.orderno:
            raise ValueError("orderno is required")
        if not order.items:
            raise ValueError("at least one item is required")
        a = order.address
        for f in ("firstname", "lastname", "street", "city", "postcode", "country"):
            if not getattr(a, f):
                raise ValueError(f"address.{f} is required")

        street = a.street + ("|" + a.street2 if a.street2 else "")

        def el(name: str, value) -> str:
            return f"<{name}>{escape(str(value))}</{name}>"

        items_xml = []
        for i, it in enumerate(order.items):
            if not it.sku:
                raise ValueError(f"item {i} has no sku")
            if int(it.quantity) < 1:
                raise ValueError(f"item {i} has quantity < 1")
            items_xml.append(
                "    <orderitem>\n"
                f"      {el('product', it.sku)}\n"
                f"      {el('childproduct', it.variation or '')}\n"
                f"      {el('quantity', int(it.quantity))}\n"
                "    </orderitem>"
            )

        ignore = "\n    <ignore_non_net1on1>1</ignore_non_net1on1>" if order.ignore_unknown_skus else ""

        return (
            '<?xml version="1.0" encoding="UTF-8"?>\n'
            "<order>\n"
            "  <dropshipper>\n"
            "    <auth>\n"
            f"      {el('id', self.email)}\n"
            f"      {el('key', self.api_key)}\n"
            "    </auth>\n"
            "  </dropshipper>\n"
            f"  {el('orderno', order.orderno)}\n"
            "  <shippingaddress>\n"
            f"    {el('firstname', a.firstname)}\n"
            f"    {el('lastname', a.lastname)}\n"
            f"    {el('street', street)}\n"
            f"    {el('city', a.city)}\n"
            f"    {el('region', a.region)}\n"
            f"    {el('postcode', a.postcode)}\n"
            f"    {el('country_id', a.country.upper())}\n"
            f"    {el('telephone', a.telephone)}\n"
            f"    {el('email', a.email)}\n"
            "  </shippingaddress>\n"
            "  <orderitems>\n"
            + "\n".join(items_xml) + "\n"
            "  </orderitems>\n"
            "  <shipping>\n"
            f"    {el('shippingtype', (order.shipping or SHIP_STANDARD).upper())}{ignore}\n"
            "  </shipping>\n"
            "</order>\n"
        )

    def send_order(self, order: Order) -> OrderResult:
        """Build and send an order. Raises Net1on1ApiError on rejection."""
        return self.send_order_xml(self.build_order_xml(order))

    def send_order_xml(self, xml: str) -> OrderResult:
        """Send XML you built yourself."""
        body = self._http("POST", self.order_endpoint, {"xmlfeed": xml})
        return parse_order_response(body)

    # ----------------------------------------------------------- status feed

    def get_orders(self, window: str = WINDOW_DEFAULT) -> List[OrderStatus]:
        """Fetch the Order Status & Tracking feed, newest first."""
        params = {"email": self.email, "key": self.api_key}
        if window == WINDOW_WEEK:
            params["week"] = "1"
        elif window == WINDOW_FULL:
            params["full"] = "1"
        url = self.status_endpoint + "?" + urllib.parse.urlencode(params)
        return parse_status_feed(self._http("GET", url))

    def find_order_by_ref(self, your_ref: str, window: str = WINDOW_DEFAULT) -> Optional[OrderStatus]:
        """Find one order by the orderno you sent."""
        for o in self.get_orders(window):
            if o.importorderref is not None and str(o.importorderref) == str(your_ref):
                return o
        return None

    # ------------------------------------------------------------- transport

    def _http(self, method: str, url: str, form: Optional[dict] = None) -> str:
        data = urllib.parse.urlencode(form).encode("utf-8") if form is not None else None
        req = urllib.request.Request(url, data=data, method=method,
                                     headers={"User-Agent": self.user_agent, "Accept": "*/*"})
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                return resp.read().decode("utf-8", errors="replace")
        except urllib.error.HTTPError as e:
            if e.code == 403:
                raise Net1on1TransportError("HTTP 403 - blocked by firewall (missing User-Agent?)") from e
            raise Net1on1TransportError(f"HTTP {e.code} from {url}") from e
        except urllib.error.URLError as e:
            raise Net1on1TransportError(f"Request failed: {e.reason}") from e


_TAG_RE = re.compile(r"<[^>]+>")


def parse_order_response(body: str) -> OrderResult:
    """Parse the Order API response body (public so logged responses can be re-parsed)."""
    text = _TAG_RE.sub("", body).strip()
    if not text:
        raise Net1on1ApiError("Empty response from Order API")
    pos = text.lower().find("fatal error")
    if pos != -1:
        raise Net1on1ApiError(text[pos:].strip())
    try:
        decoded = json.loads(text)
    except json.JSONDecodeError:
        raise Net1on1ApiError("Unexpected response from Order API: " + text[:300])
    if not isinstance(decoded, list):
        raise Net1on1ApiError("Unexpected response from Order API: " + text[:300])

    order_id = None
    lines: List[LineResult] = []
    for row in decoded:
        if not isinstance(row, dict):
            continue
        if "orderid" in row:
            order_id = str(row["orderid"]) if row["orderid"] not in (None, "") else None
            continue
        lines.append(LineResult(sku=str(row.get("sku", "")),
                                ok=not row.get("error"),
                                status=str(row.get("productstatus", ""))))
    return OrderResult(success=order_id is not None, order_id=order_id, lines=lines, raw=body)


def parse_status_feed(xml_text: str) -> List[OrderStatus]:
    """Parse the status feed XML (public so saved feeds can be re-parsed)."""
    text = xml_text.strip()
    if not text.startswith("<"):
        raise Net1on1ApiError("Status feed error: " + _TAG_RE.sub("", text)[:300])
    try:
        root = ET.fromstring(text)
    except ET.ParseError as e:
        raise Net1on1ApiError(f"Status feed returned invalid XML: {e}")
    if root.tag != "ordereport":
        raise Net1on1ApiError(f"Unexpected root element <{root.tag}>")

    def opt(o, name):
        n = o.find(name)
        return None if n is None else (n.text or "")

    def num(o, name, cast):
        v = opt(o, name)
        try:
            return cast(v) if v not in (None, "") else cast(0)
        except ValueError:
            return cast(0)

    out = []
    for o in root.findall("order"):
        out.append(OrderStatus(
            orderref=opt(o, "orderref") or "",
            status=opt(o, "status") or "",
            importorderref=opt(o, "importorderref"),
            tracking=opt(o, "tracking"),
            shiptype=opt(o, "shiptype"),
            carrier=opt(o, "carrier"),
            subtotal=num(o, "subtotal", float),
            grand_total=num(o, "grand_total", float),
            shipping_amount=num(o, "shipping_amount", float),
            tax_amount=num(o, "tax_amount", float),
            total_item_count=num(o, "total_item_count", int),
            customer_email=opt(o, "customer_email") or "",
            customer_firstname=opt(o, "customer_firstname") or "",
            customer_lastname=opt(o, "customer_lastname") or "",
            created_at=opt(o, "created_at") or "",
            updated_at=opt(o, "updated_at") or "",
        ))
    return out
