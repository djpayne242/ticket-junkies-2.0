// Email (Resend) + SMS (Twilio) delivery with a built-in log.
// Without API keys, messages are recorded in "preview" mode so they can be reviewed in the Admin Panel.
import crypto from 'node:crypto';

const LOG_LIMIT = 300;

export const escHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export function normalizePhone(input) {
  const raw = String(input || '').trim();
  if (!raw) return '';
  const digits = raw.replace(/\D/g, '');
  if (raw.startsWith('+')) return digits.length >= 8 && digits.length <= 15 ? '+' + digits : null;
  if (digits.length === 10) return '+1' + digits;                       // US / Canada / Caribbean NANP
  if (digits.length === 11 && digits.startsWith('1')) return '+' + digits;
  if (digits.length === 7) return '+1242' + digits;                     // Bahamas local number
  return null;
}

export function createNotifier({ getSettings, getLog, save, getContext = () => ({}) }) {
  const record = (entry) => {
    const log = getLog();
    log.unshift({ id: 'ntf_' + crypto.randomBytes(6).toString('hex'), createdAt: new Date().toISOString(), ...entry });
    if (log.length > LOG_LIMIT) log.length = LOG_LIMIT;
    save();
    return log[0];
  };

  async function deliverEmail({ to, subject, html, text, attachments, tag, test = false, ref = '' }) {
    const s = getSettings().notifications;
    const recipients = [].concat(to).filter(Boolean);
    if (!recipients.length) return null;
    const entry = record({ channel: 'email', tag, test, ref, to: recipients.join(', '), subject, html, text, status: 'pending', attachments: (attachments || []).map((a) => a.filename) });
    if (!s.emailEnabled || !s.resendApiKey) {
      Object.assign(entry, { status: 'preview', detail: 'Email sending is not connected yet (Admin \u2192 Settings). Saved for preview.' });
      save();
      return entry;
    }
    try {
      const res = await fetch('https://api.resend.com/emails', {
        method: 'POST',
        headers: { Authorization: `Bearer ${s.resendApiKey}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({
          from: s.emailFrom, to: recipients, subject, html, text,
          reply_to: s.replyTo || undefined,
          attachments: (attachments || []).map((a) => ({ filename: a.filename, content: a.content.toString('base64') })),
        }),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(body.message || body.error || `Resend error ${res.status}`);
      Object.assign(entry, { status: 'sent', detail: `Resend id ${body.id || ''}` });
    } catch (e) {
      Object.assign(entry, { status: 'failed', detail: String(e.message || e) });
      console.error('[email] failed:', subject, '->', entry.to, e.message);
    }
    save();
    return entry;
  }

  async function deliverSms({ to, body, tag, test = false, ref = '' }) {
    if (test && !body.startsWith('[TEST]')) body = '[TEST] ' + body;
    const s = getSettings().notifications;
    if (!to) return null;
    const entry = record({ channel: 'sms', tag, test, ref, to, subject: body.slice(0, 60), text: body, status: 'pending' });
    if (!s.smsEnabled || !s.twilioSid || !s.twilioToken || !s.twilioFrom) {
      Object.assign(entry, { status: 'preview', detail: 'Text messaging is not connected yet (Admin \u2192 Settings). Saved for preview.' });
      save();
      return entry;
    }
    try {
      const res = await fetch(`https://api.twilio.com/2010-04-01/Accounts/${encodeURIComponent(s.twilioSid)}/Messages.json`, {
        method: 'POST',
        headers: {
          Authorization: 'Basic ' + Buffer.from(`${s.twilioSid}:${s.twilioToken}`).toString('base64'),
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({ To: to, From: s.twilioFrom, Body: body }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || `Twilio error ${res.status}`);
      Object.assign(entry, { status: 'sent', detail: `Twilio sid ${data.sid || ''}` });
    } catch (e) {
      Object.assign(entry, { status: 'failed', detail: String(e.message || e) });
      console.error('[sms] failed ->', to, e.message);
    }
    save();
    return entry;
  }

  // Fire-and-forget so a slow provider never delays the website.
  // The sandbox context (test flag + order ref) is captured at call time, before the async hop.
  const email = (msg) => { const m = { ...msg, ...getContext() }; setImmediate(() => deliverEmail(m).catch((e) => console.error(e))); };
  const sms = (msg) => { const m = { ...msg, ...getContext() }; setImmediate(() => deliverSms(m).catch((e) => console.error(e))); };
  return { email, sms, deliverEmail, deliverSms };
}

// ---------- branded email layout ----------
export function emailLayout({ siteUrl, preheader = '', heading, bodyHtml, cta, footerNote = '' }) {
  const btn = cta ? `<tr><td style="padding:8px 0 4px"><a href="${escHtml(cta.url)}" style="display:inline-block;background:#eb742a;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 24px;border-radius:10px;font-size:15px">${escHtml(cta.label)}</a></td></tr>` : '';
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escHtml(heading)}</title></head>
<body style="margin:0;padding:0;background:#f2f4f8;font-family:Helvetica,Arial,sans-serif;color:#1b1f2a">
<span style="display:none;max-height:0;overflow:hidden;opacity:0">${escHtml(preheader)}</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f8;padding:24px 10px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden">
  <tr><td style="padding:22px 28px;border-bottom:4px solid #84d65f"><a href="${escHtml(siteUrl)}"><img src="${escHtml(siteUrl)}/logo.png" alt="TICKETJUNKIES.COM" width="260" style="display:block;width:260px;max-width:100%;height:auto;border:0;font-family:Impact,Arial;font-size:22px;color:#376eb8"></a></td></tr>
  <tr><td style="padding:28px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="font-size:24px;font-weight:800;padding-bottom:12px;color:#1b1f2a">${escHtml(heading)}</td></tr>
      <tr><td style="font-size:15px;line-height:1.6;color:#374151">${bodyHtml}</td></tr>
      ${btn}
    </table>
  </td></tr>
  <tr><td style="padding:18px 28px;background:#12161f;color:#9aa3b2;font-size:12px;line-height:1.5">
    ${footerNote ? escHtml(footerNote) + '<br>' : ''}TicketJunkies.com \u00b7 <a href="${escHtml(siteUrl)}" style="color:#84d65f">Browse events</a>
  </td></tr>
</table></td></tr></table></body></html>`;
}

export const detailTable = (rows) =>
  `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:14px 0;border:1px solid #e5e7eb;border-radius:10px;border-collapse:separate">${rows
    .filter(Boolean)
    .map(([k, v], i) => `<tr><td style="padding:10px 14px;color:#6b7280;font-size:13px;${i ? 'border-top:1px solid #e5e7eb;' : ''}width:38%">${escHtml(k)}</td><td style="padding:10px 14px;font-weight:600;font-size:14px;${i ? 'border-top:1px solid #e5e7eb;' : ''}">${v}</td></tr>`)
    .join('')}</table>`;
