#!/usr/bin/env bash
# Send an order to 1on1 Wholesale from an XML file.
#   ./send-order.sh order.xml
# Edit ../samples/order.xml (or your own file) and put your <id> and <key> in it first.
set -euo pipefail
XML="${1:-../samples/order.xml}"
curl -sS \
     -A "MyShop/1.0" \
     --data-urlencode "xmlfeed@${XML}" \
     "https://www.1on1wholesale.co.uk/API/order/internal-import/index.php"
echo
