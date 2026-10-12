/**
 * 1on1 Wholesale API client - Node.js 14+ (CommonJS, zero dependencies).
 *
 *   const { Net1on1Client } = require('./net1on1');
 *   const client = new Net1on1Client('you@yourshop.example');   // key derived automatically
 *   const result = await client.sendOrder({...});                // see example.js
 *   const orders = await client.getOrders('week');
 *
 * Full field reference: My Account -> API Documentation on 1on1wholesale.co.uk
 *
 * @license MIT
 */
'use strict';

const crypto = require('crypto');
const https = require('https');
const http = require('http');
const { URL, URLSearchParams } = require('url');

const ORDER_ENDPOINT = 'https://www.1on1wholesale.co.uk/API/order/internal-import/index.php';
const STATUS_ENDPOINT = 'https://www.1on1wholesale.co.uk/order_status_xml.php';

/** Shipping types for GB addresses. Anything else = STANDARD. */
const SHIP = Object.freeze({
  STANDARD: 'STANDARD', TRACKED24: 'TRACKED24', NEXTDAY: 'NEXTDAY',
  RM24: 'RM24', RM48: 'RM48', SATURDAY: 'SATURDAY',
});

/** Status feed windows. */
const WINDOW = Object.freeze({ DEFAULT: 'default', WEEK: 'week', FULL: 'full' });

class Net1on1Error extends Error {}
/** The API answered but rejected the request (bad key, duplicate order, invalid XML...). */
class Net1on1ApiError extends Net1on1Error {}
/** Network / HTTP failure (timeout, DNS, firewall 403). */
class Net1on1TransportError extends Net1on1Error {}

/** The API key is the MD5 of the account email. */
function keyForEmail(email) {
  return crypto.createHash('md5').update(String(email).trim(), 'utf8').digest('hex');
}

function esc(v) {
  return String(v == null ? '' : v)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function unesc(v) {
  return String(v)
    .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'").replace(/&#(\d+);/g, (m, n) => String.fromCodePoint(Number(n)))
    .replace(/&#x([0-9a-f]+);/gi, (m, h) => String.fromCodePoint(parseInt(h, 16)))
    .replace(/&amp;/g, '&');
}

/** Public tracking page for a feed row, or null if the carrier is unknown. */
function trackingUrl(shiptype, tracking) {
  if (!tracking) return null;
  const t = encodeURIComponent(tracking);
  switch (String(shiptype || '').replace(/\s+/g, '').toLowerCase()) {
    case 'evri': return `https://www.evri.com/track/parcel/${t}`;
    case 'dpdgroup': case 'dpd': case 'dpd.co.uk': return `https://www.dpd.co.uk/apps/tracking/?reference=${t}`;
    case 'royalmail': return `https://www.royalmail.com/track-your-item#/tracking-results/${t}`;
    case 'upsnetherlands': case 'ups': return `https://www.ups.com/track?tracknum=${t}`;
    default: return null;
  }
}

class Net1on1Client {
  /**
   * @param {string} email   Account email (the API "id").
   * @param {string} [apiKey] API key from My Account. Omit to derive from the email.
   * @param {object} [options] userAgent, timeout (ms), orderEndpoint, statusEndpoint
   */
  constructor(email, apiKey, options = {}) {
    this.email = String(email).trim();
    this.apiKey = apiKey || keyForEmail(this.email);
    this.userAgent = options.userAgent || 'Net1on1Client-Node/1.0'; // required - empty UA is firewalled
    this.timeout = options.timeout || 90000;
    this.orderEndpoint = options.orderEndpoint || ORDER_ENDPOINT;
    this.statusEndpoint = options.statusEndpoint || STATUS_ENDPOINT;
  }

  // ---------------------------------------------------------------- orders

  /**
   * Build the order XML from a plain object:
   * {
   *   orderno: 'YOUR-123',                      // required, unique per account
   *   shipping: 'TRACKED24',                    // optional, GB only
   *   ignoreUnknownSkus: false,                 // optional
   *   address: { firstname, lastname, street, street2?, city, region?, postcode, country, telephone?, email? },
   *   items: [ { sku: 'N0533', quantity: 1 }, { sku: 'N9127', variation: 'NS6335', quantity: 2 } ],
   * }
   */
  buildOrderXml(order) {
    if (!order || !order.orderno) throw new TypeError("order.orderno is required");
    if (!order.address) throw new TypeError('order.address is required');
    if (!Array.isArray(order.items) || order.items.length === 0) throw new TypeError('order.items must be a non-empty array');
    const a = order.address;
    for (const f of ['firstname', 'lastname', 'street', 'city', 'postcode', 'country']) {
      if (!a[f]) throw new TypeError(`address.${f} is required`);
    }
    const street = a.street2 ? `${a.street}|${a.street2}` : a.street;

    const items = order.items.map((it, i) => {
      if (!it.sku) throw new TypeError(`items[${i}].sku is required`);
      const qty = parseInt(it.quantity == null ? 1 : it.quantity, 10);
      if (!(qty >= 1)) throw new TypeError(`items[${i}].quantity must be >= 1`);
      return [
        '    <orderitem>',
        `      <product>${esc(it.sku)}</product>`,
        `      <childproduct>${esc(it.variation || '')}</childproduct>`,
        `      <quantity>${qty}</quantity>`,
        '    </orderitem>',
      ].join('\n');
    }).join('\n');

    const ignore = order.ignoreUnknownSkus ? '\n    <ignore_non_net1on1>1</ignore_non_net1on1>' : '';

    return [
      '<?xml version="1.0" encoding="UTF-8"?>',
      '<order>',
      '  <dropshipper>',
      '    <auth>',
      `      <id>${esc(this.email)}</id>`,
      `      <key>${esc(this.apiKey)}</key>`,
      '    </auth>',
      '  </dropshipper>',
      `  <orderno>${esc(order.orderno)}</orderno>`,
      '  <shippingaddress>',
      `    <firstname>${esc(a.firstname)}</firstname>`,
      `    <lastname>${esc(a.lastname)}</lastname>`,
      `    <street>${esc(street)}</street>`,
      `    <city>${esc(a.city)}</city>`,
      `    <region>${esc(a.region || '')}</region>`,
      `    <postcode>${esc(a.postcode)}</postcode>`,
      `    <country_id>${esc(String(a.country).toUpperCase())}</country_id>`,
      `    <telephone>${esc(a.telephone || '')}</telephone>`,
      `    <email>${esc(a.email || '')}</email>`,
      '  </shippingaddress>',
      '  <orderitems>',
      items,
      '  </orderitems>',
      '  <shipping>',
      `    <shippingtype>${esc(String(order.shipping || SHIP.STANDARD).toUpperCase())}</shippingtype>${ignore}`,
      '  </shipping>',
      '</order>',
      '',
    ].join('\n');
  }

  /**
   * Build and send an order.
   * @returns {Promise<{success: boolean, orderId: string|null, lines: {sku, ok, status}[], raw: string}>}
   * @throws Net1on1ApiError on a "Fatal error:" rejection, Net1on1TransportError on network failure
   */
  async sendOrder(order) {
    return this.sendOrderXml(this.buildOrderXml(order));
  }

  /** Send XML you built yourself. */
  async sendOrderXml(xml) {
    const body = await this._http('POST', this.orderEndpoint, { xmlfeed: xml });
    return parseOrderResponse(body);
  }

  // ----------------------------------------------------------- status feed

  /**
   * Fetch the Order Status & Tracking feed, newest first.
   * @param {'default'|'week'|'full'} [window]
   * @returns {Promise<object[]>} see parseStatusFeed()
   */
  async getOrders(window = WINDOW.DEFAULT) {
    const params = new URLSearchParams({ email: this.email, key: this.apiKey });
    if (window === WINDOW.WEEK) params.set('week', '1');
    else if (window === WINDOW.FULL) params.set('full', '1');
    const body = await this._http('GET', `${this.statusEndpoint}?${params}`);
    return parseStatusFeed(body);
  }

  /** Find one order by the orderno you sent. */
  async findOrderByRef(yourRef, window = WINDOW.DEFAULT) {
    const orders = await this.getOrders(window);
    return orders.find((o) => o.importorderref != null && String(o.importorderref) === String(yourRef)) || null;
  }

  // ------------------------------------------------------------- transport

  _http(method, urlStr, form) {
    return new Promise((resolve, reject) => {
      const url = new URL(urlStr);
      const data = form ? new URLSearchParams(form).toString() : null;
      const headers = { 'User-Agent': this.userAgent, Accept: '*/*' };
      if (data) {
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        headers['Content-Length'] = Buffer.byteLength(data);
      }
      const lib = url.protocol === 'http:' ? http : https;
      const req = lib.request(url, { method, headers, timeout: this.timeout }, (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const body = Buffer.concat(chunks).toString('utf8');
          if (res.statusCode === 403) return reject(new Net1on1TransportError('HTTP 403 - blocked by firewall (missing User-Agent?)'));
          if (res.statusCode >= 400) return reject(new Net1on1TransportError(`HTTP ${res.statusCode} from ${urlStr}`));
          resolve(body);
        });
      });
      req.on('timeout', () => { req.destroy(new Error('timeout')); });
      req.on('error', (e) => reject(new Net1on1TransportError(`Request failed: ${e.message}`)));
      if (data) req.write(data);
      req.end();
    });
  }
}

/** Parse the Order API response body (public so logged responses can be re-parsed). */
function parseOrderResponse(body) {
  const text = String(body).replace(/<[^>]+>/g, '').trim();
  if (!text) throw new Net1on1ApiError('Empty response from Order API');
  const pos = text.toLowerCase().indexOf('fatal error');
  if (pos !== -1) throw new Net1on1ApiError(text.slice(pos).trim());
  let decoded;
  try { decoded = JSON.parse(text); } catch (e) {
    throw new Net1on1ApiError('Unexpected response from Order API: ' + text.slice(0, 300));
  }
  if (!Array.isArray(decoded)) throw new Net1on1ApiError('Unexpected response from Order API: ' + text.slice(0, 300));

  let orderId = null;
  const lines = [];
  for (const row of decoded) {
    if (!row || typeof row !== 'object') continue;
    if ('orderid' in row) {
      orderId = row.orderid == null || row.orderid === '' ? null : String(row.orderid);
      continue;
    }
    lines.push({ sku: String(row.sku || ''), ok: !row.error, status: String(row.productstatus || '') });
  }
  return { success: orderId !== null, orderId, lines, raw: body };
}

/**
 * Parse the status feed XML (flat schema, so a small purpose-built parser is enough).
 * Each order: { orderref, status, importorderref|null, tracking|null, shiptype|null, carrier|null,
 *   subtotal, grand_total, shipping_amount, tax_amount (numbers), total_item_count (int),
 *   customer_email, customer_firstname, customer_lastname, created_at, updated_at }
 */
function parseStatusFeed(xmlText) {
  const text = String(xmlText).trim();
  if (!text.startsWith('<')) throw new Net1on1ApiError('Status feed error: ' + text.replace(/<[^>]+>/g, '').slice(0, 300));
  if (!/<ordereport\b/.test(text)) throw new Net1on1ApiError('Unexpected status feed response');

  const orders = [];
  const orderRe = /<order>([\s\S]*?)<\/order>/g;
  const fieldRe = /<([a-z_]+)>([\s\S]*?)<\/\1>|<([a-z_]+)\s*\/>/g;
  let m;
  while ((m = orderRe.exec(text)) !== null) {
    const f = {};
    let fm;
    while ((fm = fieldRe.exec(m[1])) !== null) {
      if (fm[1]) f[fm[1]] = unesc(fm[2]); else f[fm[3]] = '';
    }
    const num = (k) => { const v = parseFloat(f[k]); return Number.isFinite(v) ? v : 0; };
    orders.push({
      orderref: f.orderref || '',
      status: f.status || '',
      importorderref: 'importorderref' in f ? f.importorderref : null,
      tracking: 'tracking' in f ? f.tracking : null,
      shiptype: 'shiptype' in f ? f.shiptype : null,
      carrier: 'carrier' in f ? f.carrier : null,
      subtotal: num('subtotal'),
      grand_total: num('grand_total'),
      shipping_amount: num('shipping_amount'),
      tax_amount: num('tax_amount'),
      total_item_count: parseInt(f.total_item_count, 10) || 0,
      customer_email: f.customer_email || '',
      customer_firstname: f.customer_firstname || '',
      customer_lastname: f.customer_lastname || '',
      created_at: f.created_at || '',
      updated_at: f.updated_at || '',
    });
  }
  return orders;
}

module.exports = {
  Net1on1Client, Net1on1Error, Net1on1ApiError, Net1on1TransportError,
  keyForEmail, trackingUrl, parseOrderResponse, parseStatusFeed,
  SHIP, WINDOW, ORDER_ENDPOINT, STATUS_ENDPOINT,
};
