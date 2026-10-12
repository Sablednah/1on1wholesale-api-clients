#!/usr/bin/env bash
# Fetch the Order Status & Tracking feed.
#   EMAIL=you@yourshop.example KEY=yourkey ./status-feed.sh            # last 14 days
#   EMAIL=you@yourshop.example KEY=yourkey ./status-feed.sh week       # last 7 days
#   EMAIL=you@yourshop.example KEY=yourkey ./status-feed.sh full       # everything (slow)
# KEY may be omitted - it is the md5 of the email.
set -euo pipefail
: "${EMAIL:?set EMAIL}"
KEY="${KEY:-$(printf '%s' "$EMAIL" | md5sum | cut -d' ' -f1)}"
WINDOW="${1:-}"
EXTRA=""
[ "$WINDOW" = "week" ] && EXTRA="&week=1"
[ "$WINDOW" = "full" ] && EXTRA="&full=1"
curl -sS -A "MyShop/1.0" -G \
     --data-urlencode "email=${EMAIL}" \
     --data-urlencode "key=${KEY}" \
     "https://www.1on1wholesale.co.uk/order_status_xml.php?x=1${EXTRA}"
echo
