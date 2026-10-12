const { Net1on1Client, Net1on1ApiError, Net1on1TransportError, SHIP, WINDOW, trackingUrl } = require('./net1on1');

// ID = the email you log in to 1on1wholesale.co.uk with.
// Key = shown under My Account -> API Documentation (or omit it and it is derived).
const client = new Net1on1Client('you@yourshop.example', 'your-api-key-here');

(async () => {
  // ---- 1. Send an order -----------------------------------------------------
  try {
    const result = await client.sendOrder({
      orderno: 'SHOP-10001',               // your reference - must be unique
      shipping: SHIP.TRACKED24,
      address: {
        firstname: 'Jane', lastname: 'Smith',
        street: '12 High Street', street2: 'Flat 3',
        city: 'Manchester', region: 'Greater Manchester',
        postcode: 'M1 1AA', country: 'GB',
        telephone: '07700900123', email: 'jane.smith@example.com',
      },
      items: [
        { sku: 'N13125', quantity: 1 },
        { sku: 'N12946', variation: 'NS8102', quantity: 2 },
      ],
    });

    if (result.success) {
      console.log('Order created: 1on1 order number', result.orderId);
    } else {
      console.log('Order NOT created. Problem lines:');
      for (const line of result.lines) if (!line.ok) console.log(`  ${line.sku}: ${line.status}`);
    }
  } catch (e) {
    if (e instanceof Net1on1ApiError) console.log('Rejected:', e.message);        // bad key, duplicate, invalid XML
    else if (e instanceof Net1on1TransportError) console.log('Network problem:', e.message);
    else throw e;
  }

  // ---- 2. Poll the status feed ---------------------------------------------
  for (const o of await client.getOrders(WINDOW.WEEK)) {
    console.log(o.orderref, o.status, o.importorderref || '-', o.shiptype || '', o.tracking || '',
      trackingUrl(o.shiptype, o.tracking) || '');
  }

  // ---- 3. Look up one order by your own reference ---------------------------
  const o = await client.findOrderByRef('SHOP-10001');
  console.log(o ? `SHOP-10001 is 1on1 order ${o.orderref} (${o.status})` : 'SHOP-10001 not found in window');
})();
