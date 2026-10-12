<?php
/**
 * 1on1 Wholesale API client - PHP.
 *
 * Single-file. Needs ext-curl, ext-simplexml and ext-json (all standard in
 * every mainstream PHP build). PHP 7.4 or newer.
 *
 *   require 'Net1on1Client.php';
 *   $client = new Net1on1Client('you@yourshop.example');          // key derived automatically
 *   $result = $client->sendOrder([...]);                            // see README / example.php
 *   $orders = $client->getOrders(Net1on1Client::WINDOW_WEEK);
 *
 * Full field reference: My Account -> API Documentation on 1on1wholesale.co.uk
 *
 * @license MIT
 */

class Net1on1Exception extends RuntimeException {}

/** The API answered but rejected the request (bad key, duplicate order, invalid XML...). */
class Net1on1ApiException extends Net1on1Exception {}

/** Transport-level failure (DNS, timeout, HTTP error, firewall 403). */
class Net1on1TransportException extends Net1on1Exception {}

class Net1on1Client
{
    public const ORDER_ENDPOINT  = 'https://www.1on1wholesale.co.uk/API/order/internal-import/index.php';
    public const STATUS_ENDPOINT = 'https://www.1on1wholesale.co.uk/order_status_xml.php';

    /** Status feed windows. */
    public const WINDOW_DEFAULT = 'default'; // last 14 days
    public const WINDOW_WEEK    = 'week';    // last 7 days
    public const WINDOW_FULL    = 'full';    // everything - slow, initial import only

    /** Shipping types for GB addresses. Anything else = STANDARD. */
    public const SHIP_STANDARD  = 'STANDARD';
    public const SHIP_TRACKED24 = 'TRACKED24';
    public const SHIP_NEXTDAY   = 'NEXTDAY';
    public const SHIP_RM24      = 'RM24';
    public const SHIP_RM48      = 'RM48';
    public const SHIP_SATURDAY  = 'SATURDAY';

    private string $email;
    private string $apiKey;
    private string $userAgent;
    private int $timeout;
    private string $orderEndpoint;
    private string $statusEndpoint;

    /**
     * @param string      $email   Account email (the API "id").
     * @param string|null $apiKey  API key from My Account. Omit to derive it from the email.
     * @param array       $options user_agent (string), timeout (seconds),
     *                             order_endpoint / status_endpoint (override, e.g. staging).
     */
    public function __construct(string $email, ?string $apiKey = null, array $options = [])
    {
        $this->email          = trim($email);
        $this->apiKey         = $apiKey ?? self::keyForEmail($this->email);
        $this->userAgent      = $options['user_agent']      ?? 'Net1on1Client-PHP/1.0';
        $this->timeout        = (int)($options['timeout']   ?? 90);
        $this->orderEndpoint  = $options['order_endpoint']  ?? self::ORDER_ENDPOINT;
        $this->statusEndpoint = $options['status_endpoint'] ?? self::STATUS_ENDPOINT;
    }

    /** The API key is the MD5 of the lower-cased account email. */
    public static function keyForEmail(string $email): string
    {
        return md5(trim($email));
    }

    // ------------------------------------------------------------------ orders

    /**
     * Build the order XML from a plain array.
     *
     * [
     *   'orderno'  => 'YOUR-123',                 // required, unique per account
     *   'shipping' => 'TRACKED24',                // optional, GB only
     *   'ignore_unknown_skus' => false,           // optional
     *   'address'  => [
     *     'firstname' => 'Jane', 'lastname' => 'Smith',
     *     'street'    => '12 High Street', 'street2' => 'Flat 3',   // street2 optional
     *     'city'      => 'Manchester', 'region' => 'Greater Manchester',
     *     'postcode'  => 'M1 1AA', 'country' => 'GB',
     *     'telephone' => '07700900123', 'email' => 'jane@example.com',
     *   ],
     *   'items' => [
     *     ['sku' => 'N13125', 'quantity' => 1],
     *     ['sku' => 'N12946', 'variation' => 'NS8102', 'quantity' => 2],
     *   ],
     * ]
     */
    public function buildOrderXml(array $order): string
    {
        foreach (['orderno', 'address', 'items'] as $k) {
            if (empty($order[$k])) {
                throw new InvalidArgumentException("Order is missing '$k'");
            }
        }
        $a = $order['address'];
        foreach (['firstname', 'lastname', 'street', 'city', 'postcode', 'country'] as $k) {
            if (!isset($a[$k]) || $a[$k] === '') {
                throw new InvalidArgumentException("Address is missing '$k'");
            }
        }

        $street = $a['street'];
        if (!empty($a['street2'])) {
            $street .= '|' . $a['street2'];
        }

        $el = static function (string $name, $value): string {
            return "<$name>" . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</$name>";
        };

        $items = [];
        foreach ($order['items'] as $i => $item) {
            if (empty($item['sku'])) {
                throw new InvalidArgumentException("Item $i is missing 'sku'");
            }
            $qty = (int)($item['quantity'] ?? 1);
            if ($qty < 1) {
                throw new InvalidArgumentException("Item $i has quantity < 1");
            }
            $items[] = "    <orderitem>\n"
                . '      ' . $el('product', $item['sku']) . "\n"
                . '      ' . $el('childproduct', $item['variation'] ?? '') . "\n"
                . '      ' . $el('quantity', $qty) . "\n"
                . "    </orderitem>";
        }

        $ignore = !empty($order['ignore_unknown_skus']) ? "\n    <ignore_non_net1on1>1</ignore_non_net1on1>" : '';

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . "<order>\n"
            . "  <dropshipper>\n"
            . "    <auth>\n"
            . '      ' . $el('id', $this->email) . "\n"
            . '      ' . $el('key', $this->apiKey) . "\n"
            . "    </auth>\n"
            . "  </dropshipper>\n"
            . '  ' . $el('orderno', $order['orderno']) . "\n"
            . "  <shippingaddress>\n"
            . '    ' . $el('firstname', $a['firstname']) . "\n"
            . '    ' . $el('lastname', $a['lastname']) . "\n"
            . '    ' . $el('street', $street) . "\n"
            . '    ' . $el('city', $a['city']) . "\n"
            . '    ' . $el('region', $a['region'] ?? '') . "\n"
            . '    ' . $el('postcode', $a['postcode']) . "\n"
            . '    ' . $el('country_id', strtoupper((string)$a['country'])) . "\n"
            . '    ' . $el('telephone', $a['telephone'] ?? '') . "\n"
            . '    ' . $el('email', $a['email'] ?? '') . "\n"
            . "  </shippingaddress>\n"
            . "  <orderitems>\n"
            . implode("\n", $items) . "\n"
            . "  </orderitems>\n"
            . "  <shipping>\n"
            . '    ' . $el('shippingtype', strtoupper((string)($order['shipping'] ?? self::SHIP_STANDARD))) . $ignore . "\n"
            . "  </shipping>\n"
            . "</order>\n";
    }

    /**
     * Build and send an order.
     *
     * @return array {
     *   success:  bool          true when an order was created
     *   order_id: string|null   1on1 order number (the status feed's <orderref>)
     *   lines:    array         [{sku, ok (bool), status ('In stock'|'out of stock'|'not available'|'variation required ...')}]
     *   raw:      string        raw response body
     * }
     * @throws Net1on1ApiException on a "Fatal error:" rejection
     * @throws Net1on1TransportException on network / HTTP failure
     */
    public function sendOrder(array $order): array
    {
        return $this->sendOrderXml($this->buildOrderXml($order));
    }

    /** Send XML you have built yourself. Same return / exceptions as sendOrder(). */
    public function sendOrderXml(string $xml): array
    {
        $body = $this->http('POST', $this->orderEndpoint, ['xmlfeed' => $xml]);
        return self::parseOrderResponse($body);
    }

    /** Parse the Order API response body. Public so you can re-parse logged responses. */
    public static function parseOrderResponse(string $body): array
    {
        $trimmed = trim(strip_tags($body));
        if ($trimmed === '' ) {
            throw new Net1on1ApiException('Empty response from Order API');
        }
        if (stripos($trimmed, 'Fatal error') !== false) {
            // strip anything before the error text (debug output, stray HTML)
            $pos = stripos($trimmed, 'Fatal error');
            throw new Net1on1ApiException(trim(substr($trimmed, $pos)));
        }
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            throw new Net1on1ApiException('Unexpected response from Order API: ' . substr($trimmed, 0, 300));
        }

        $orderId = null;
        $lines   = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (array_key_exists('orderid', $row)) {
                $orderId = $row['orderid'] !== null ? (string)$row['orderid'] : null;
                continue;
            }
            $lines[] = [
                'sku'    => (string)($row['sku'] ?? ''),
                'ok'     => empty($row['error']),
                'status' => (string)($row['productstatus'] ?? ''),
            ];
        }
        return [
            'success'  => $orderId !== null && $orderId !== '',
            'order_id' => $orderId,
            'lines'    => $lines,
            'raw'      => $body,
        ];
    }

    // ------------------------------------------------------------- status feed

    /**
     * Fetch the Order Status & Tracking feed.
     *
     * @param string $window WINDOW_DEFAULT (14 days), WINDOW_WEEK (7 days) or WINDOW_FULL (all).
     * @return array[] one assoc array per order, newest first:
     *   orderref, status, importorderref (null unless API order), tracking (null until shipped),
     *   shiptype, carrier, subtotal, grand_total, shipping_amount, tax_amount (floats),
     *   total_item_count (int), customer_email, customer_firstname, customer_lastname,
     *   created_at, updated_at (ISO 8601 strings)
     */
    public function getOrders(string $window = self::WINDOW_DEFAULT): array
    {
        $params = ['email' => $this->email, 'key' => $this->apiKey];
        if ($window === self::WINDOW_WEEK) {
            $params['week'] = 1;
        } elseif ($window === self::WINDOW_FULL) {
            $params['full'] = 1;
        }
        $url  = $this->statusEndpoint . '?' . http_build_query($params);
        $body = $this->http('GET', $url);
        return self::parseStatusFeed($body);
    }

    /** Find one order by your own reference (the orderno you sent). */
    public function findOrderByRef(string $yourRef, string $window = self::WINDOW_DEFAULT): ?array
    {
        foreach ($this->getOrders($window) as $o) {
            if ($o['importorderref'] !== null && (string)$o['importorderref'] === (string)$yourRef) {
                return $o;
            }
        }
        return null;
    }

    /** Parse the status feed XML. Public so you can re-parse saved feeds. */
    public static function parseStatusFeed(string $xml): array
    {
        $trimmed = trim($xml);
        if ($trimmed === '' || $trimmed[0] !== '<') {
            throw new Net1on1ApiException('Status feed error: ' . substr(strip_tags($trimmed), 0, 300));
        }
        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($trimmed);
        libxml_use_internal_errors($prev);
        if ($doc === false || $doc->getName() !== 'ordereport') {
            throw new Net1on1ApiException('Status feed returned invalid XML');
        }
        $orders = [];
        foreach ($doc->order as $o) {
            $get = function (string $name) use ($o) {
                return isset($o->{$name}) ? (string)$o->{$name} : null;
            };
            $orders[] = [
                'orderref'           => $get('orderref'),
                'status'             => $get('status'),
                'importorderref'     => $get('importorderref'),
                'tracking'           => $get('tracking'),
                'shiptype'           => $get('shiptype'),
                'carrier'            => $get('carrier'),
                'subtotal'           => (float)$get('subtotal'),
                'grand_total'        => (float)$get('grand_total'),
                'shipping_amount'    => (float)$get('shipping_amount'),
                'tax_amount'         => (float)$get('tax_amount'),
                'total_item_count'   => (int)$get('total_item_count'),
                'customer_email'     => $get('customer_email'),
                'customer_firstname' => $get('customer_firstname'),
                'customer_lastname'  => $get('customer_lastname'),
                'created_at'         => $get('created_at'),
                'updated_at'         => $get('updated_at'),
            ];
        }
        return $orders;
    }

    /** Public tracking page for a feed row, or null if the carrier is unknown. */
    public static function trackingUrl(?string $shiptype, ?string $tracking): ?string
    {
        if (!$tracking) {
            return null;
        }
        $t = rawurlencode($tracking);
        switch (strtolower(str_replace(' ', '', (string)$shiptype))) {
            case 'evri':      return "https://www.evri.com/track/parcel/$t";
            case 'dpdgroup':
            case 'dpd':
            case 'dpd.co.uk': return "https://www.dpd.co.uk/apps/tracking/?reference=$t";
            case 'royalmail': return "https://www.royalmail.com/track-your-item#/tracking-results/$t";
            case 'upsnetherlands':
            case 'ups':       return "https://www.ups.com/track?tracknum=$t";
            default:          return null;
        }
    }

    // --------------------------------------------------------------- transport

    private function http(string $method, string $url, ?array $form = null): string
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => $this->userAgent, // required - empty UA is firewalled
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form ?? []);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new Net1on1TransportException("Request failed: $err");
        }
        if ($code === 403) {
            throw new Net1on1TransportException('HTTP 403 - request blocked by firewall (missing User-Agent?)');
        }
        if ($code >= 400) {
            throw new Net1on1TransportException("HTTP $code from $url");
        }
        return (string)$body;
    }
}
