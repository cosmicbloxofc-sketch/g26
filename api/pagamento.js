// Vercel Serverless — POST /api/pagamento?act=wintech_new  (proxy p/ Hostinger)
// Pix.html no Vercel usa este endpoint; Hostinger continua com pagamento/api.php
export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') { res.status(204).end(); return; }
  const act = req.query.act || 'wintech_new';
  const hostingerUrl = `https://informeonline.org/g26/pagamento/api.php?act=${encodeURIComponent(act)}`;
  // Forward query string (fbclid, sck, utm, etc come via ?act=..&fbclid=..)
  const qs = new URL(req.url || '', 'http://localhost').search;
  const forwardUrl = qs ? `${hostingerUrl}${hostingerUrl.includes('?') ? '&' : '?'}${qs.slice(1)}` : hostingerUrl;
  // Actually just use hostingerUrl + extra qs
  const extraQs = req.url && req.url.includes('?') ? req.url.slice(req.url.indexOf('?')) : '';
  const url = `https://informeonline.org/g26/pagamento/api.php${extraQs || `?act=${act}`}`;
  try {
    const body = req.body ? (typeof req.body === 'string' ? req.body : JSON.stringify(req.body)) : (req.body === undefined ? undefined : JSON.stringify(req.body));
    // vercel gives parsed body as object; re-stringify
    const rawBody = body !== undefined ? body : (req.method === 'POST' ? JSON.stringify(req.body || {}) : undefined);
    const r = await fetch(url, {
      method: req.method,
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: rawBody,
    });
    const text = await r.text();
    res.setHeader('Content-Type', r.headers.get('content-type') || 'application/json');
    res.status(r.status).send(text);
  } catch (e) {
    res.status(502).json({ error: 'Falha de conexao com o pagamento', details: e.message });
  }
}
