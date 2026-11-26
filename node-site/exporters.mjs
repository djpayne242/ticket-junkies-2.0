// Report exporters: PDF, Word (.docx), Excel (.xlsx) and CSV — no external dependencies.
// All exporters render the same "document model":
//   { title, subtitle, meta: [[label, value]...], sections: [{ title, columns: [{ label, width, type }], rows: [[...]], totals? }] }
// Column types: 'text' | 'money' | 'int' | 'date'
import zlib from 'node:zlib';

// ---------------------------------------------------------------- ZIP (for xlsx / docx)
const CRC = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; }
  return t;
})();
const crc32 = (buf) => { let c = 0xffffffff; for (const b of buf) c = CRC[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };

export function zip(files) {
  const now = new Date();
  const dosTime = (now.getHours() << 11) | (now.getMinutes() << 5) | (now.getSeconds() >> 1);
  const dosDate = ((now.getFullYear() - 1980) << 9) | ((now.getMonth() + 1) << 5) | now.getDate();
  const locals = []; const centrals = []; let offset = 0;
  for (const f of files) {
    const name = Buffer.from(f.name, 'utf8');
    const data = Buffer.isBuffer(f.data) ? f.data : Buffer.from(f.data, 'utf8');
    const comp = zlib.deflateRawSync(data, { level: 9 });
    const crc = crc32(data);
    const lh = Buffer.alloc(30);
    lh.writeUInt32LE(0x04034b50, 0); lh.writeUInt16LE(20, 4); lh.writeUInt16LE(0x0800, 6); lh.writeUInt16LE(8, 8);
    lh.writeUInt16LE(dosTime, 10); lh.writeUInt16LE(dosDate, 12); lh.writeUInt32LE(crc, 14);
    lh.writeUInt32LE(comp.length, 18); lh.writeUInt32LE(data.length, 22); lh.writeUInt16LE(name.length, 26); lh.writeUInt16LE(0, 28);
    locals.push(lh, name, comp);
    const ch = Buffer.alloc(46);
    ch.writeUInt32LE(0x02014b50, 0); ch.writeUInt16LE(20, 4); ch.writeUInt16LE(20, 6); ch.writeUInt16LE(0x0800, 8); ch.writeUInt16LE(8, 10);
    ch.writeUInt16LE(dosTime, 12); ch.writeUInt16LE(dosDate, 14); ch.writeUInt32LE(crc, 16); ch.writeUInt32LE(comp.length, 20);
    ch.writeUInt32LE(data.length, 24); ch.writeUInt16LE(name.length, 28); ch.writeUInt32LE(offset, 42);
    centrals.push(ch, name);
    offset += lh.length + name.length + comp.length;
  }
  const cd = Buffer.concat(centrals);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0); end.writeUInt16LE(files.length, 8); end.writeUInt16LE(files.length, 10);
  end.writeUInt32LE(cd.length, 12); end.writeUInt32LE(offset, 16);
  return Buffer.concat([...locals, cd, end]);
}

// ---------------------------------------------------------------- helpers
const xml = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])).replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g, '');
const fmtMoney = (n) => (n == null || n === '' ? '' : (n < 0 ? '-$' : '$') + Math.abs(Number(n)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const fmtDate = (v) => (v ? new Date(v).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '');
export const cellText = (v, type) => (type === 'money' ? fmtMoney(v) : type === 'date' ? fmtDate(v) : type === 'int' ? (v == null ? '' : String(v)) : String(v ?? ''));

export const MONEY_KEYS = new Set(['Ticket sales','Platform fees','Service fees (buyers)','Total collected','Avg order','Refunded amount']);
const isMoneyKey = (k) => MONEY_KEYS.has(k);

// ---------------------------------------------------------------- CSV
export function toCsv(model) {
  const q = (x) => { const s = String(x ?? ''); const safe = /^[=+\-@]/.test(s) ? "'" + s : s; return /[",\r\n]/.test(safe) || safe !== s ? `"${safe.replace(/"/g, '""')}"` : safe; };
  const out = [[model.title], [model.subtitle], []];
  for (const [k, v] of model.meta || []) out.push([k, isMoneyKey(k) ? Number(v).toFixed(2) : v]);
  for (const s of model.sections) {
    out.push([], [s.title], s.columns.map((c) => c.label));
    for (const r of s.rows) out.push(r.map((v, i) => (s.columns[i].type === 'money' ? (v === '' || v == null ? '' : Number(v).toFixed(2)) : cellText(v, s.columns[i].type))));
    if (s.totals) out.push(s.totals.map((v, i) => (s.columns[i].type === 'money' && v !== '' ? Number(v).toFixed(2) : v ?? '')));
  }
  return '\ufeff' + out.map((r) => r.map(q).join(',')).join('\r\n');
}

// ---------------------------------------------------------------- XLSX
function colName(i) { let s = ''; i++; while (i) { const m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); } return s; }
function excelDate(v) {
  const d = new Date(v);
  if (isNaN(d)) return null;
  return (Date.UTC(d.getFullYear(), d.getMonth(), d.getDate(), d.getHours(), d.getMinutes(), d.getSeconds()) / 86400000) + 25569;
}
export function toXlsx(model) {
  // styles: 0 normal, 1 header, 2 money, 3 total-text, 4 total-money, 5 title, 6 subtitle, 7 date, 8 int, 9 total-int, 10 meta label
  const styles = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="2"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/><numFmt numFmtId="165" formatCode="mmm d, yyyy h:mm AM/PM"/></numFmts>
<fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="18"/><color rgb="FF376EB8"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font></fonts>
<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF376EB8"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEEF2F7"/><bgColor indexed="64"/></patternFill></fill></fills>
<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top style="thin"><color rgb="FF9CA3AF"/></top><bottom/><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="11">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>
<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf>
<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="164" fontId="2" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left" vertical="top"/></xf>
<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf>
<xf numFmtId="1" fontId="2" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
</cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>`;

  const sheets = [];
  const usedNames = new Set();
  const sheetName = (t) => {
    let n = t.replace(/[\[\]:*?/\\]/g, '').replace(/[^\x20-\x7E\u00A0-\uFFFF]/g, '').replace(/[\u{1F000}-\u{1FFFF}\u2600-\u27BF]/gu, '').trim().slice(0, 31) || 'Sheet';
    let k = 2, base = n; while (usedNames.has(n.toLowerCase())) n = (base.slice(0, 28) + ' ' + k++);
    usedNames.add(n.toLowerCase()); return n;
  };
  const str = (ref, v, s = 0) => `<c r="${ref}" t="inlineStr" s="${s}"><is><t xml:space="preserve">${xml(v)}</t></is></c>`;
  const num = (ref, v, s) => `<c r="${ref}" s="${s}"><v>${Number(v)}</v></c>`;

  // Summary sheet
  {
    const rows = [`<row r="1">${str('A1', model.title, 5)}</row>`, `<row r="2">${str('A2', model.subtitle, 6)}</row>`];
    let r = 4;
    for (const [k, v] of model.meta || []) {
      const isMoney = typeof v === 'number' && isMoneyKey(k);
      rows.push(`<row r="${r}">${str('A' + r, k, 10)}${typeof v === 'number' ? num('B' + r, v, isMoney ? 2 : 8) : str('B' + r, v)}</row>`);
      r++;
    }
    sheets.push({ name: sheetName('Summary'), xml: `<cols><col min="1" max="1" width="34" customWidth="1"/><col min="2" max="2" width="48" customWidth="1"/></cols><sheetData>${rows.join('')}</sheetData>` });
  }

  for (const sec of model.sections) {
    const cols = sec.columns;
    const head = 4;
    const rows = [`<row r="1">${str('A1', sec.title, 5)}</row>`, `<row r="2">${str('A2', model.subtitle, 6)}</row>`];
    rows.push(`<row r="${head}" ht="20" customHeight="1">${cols.map((c, i) => str(colName(i) + head, c.label, 1)).join('')}</row>`);
    let r = head + 1;
    for (const row of sec.rows) {
      rows.push(`<row r="${r}">${row.map((v, i) => {
        const ref = colName(i) + r, t = cols[i].type;
        if (v === '' || v == null) return '';
        if (t === 'money') return num(ref, v, 2);
        if (t === 'int') return num(ref, v, 8);
        if (t === 'date') { const d = excelDate(v); return d == null ? str(ref, v) : num(ref, d, 7); }
        return str(ref, v);
      }).join('')}</row>`);
      r++;
    }
    if (sec.totals) {
      rows.push(`<row r="${r}">${sec.totals.map((v, i) => {
        const ref = colName(i) + r, t = cols[i].type;
        if (v === '' || v == null) return `<c r="${ref}" s="3"/>`;
        if (t === 'money') return num(ref, v, 4);
        if (t === 'int' && typeof v === 'number') return num(ref, v, 9);
        return str(ref, v, 3);
      }).join('')}</row>`);
    }
    const last = colName(cols.length - 1);
    const lastData = Math.max(head, head + sec.rows.length);
    sheets.push({
      name: sheetName(sec.sheet || sec.title),
      xml: `<sheetViews><sheetView workbookViewId="0"><pane ySplit="${head}" topLeftCell="A${head + 1}" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>`
        + `<cols>${cols.map((c, i) => `<col min="${i + 1}" max="${i + 1}" width="${Math.max(8, Math.min(60, (c.width || 12) * 1.15)).toFixed(1)}" customWidth="1"/>`).join('')}</cols>`
        + `<sheetData>${rows.join('')}</sheetData>`
        + (sec.rows.length ? `<autoFilter ref="A${head}:${last}${lastData}"/>` : ''),
    });
  }

  const ws = (body) => `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">${body}<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>`;
  // pageSetup with fitTo requires sheetPr fitToPage; keep simple and valid:
  const wsFixed = (body) => ws(body).replace('<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>', '<pageSetup orientation="landscape"/>');
  const files = [
    { name: '[Content_Types].xml', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>${sheets.map((_, i) => `<Override PartName="/xl/worksheets/sheet${i + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join('')}<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>` },
    { name: '_rels/.rels', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>` },
    { name: 'docProps/core.xml', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>${xml(model.title)}</dc:title><dc:creator>TicketJunkies.com</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">${new Date().toISOString().replace(/\.\d+Z$/, 'Z')}</dcterms:created></cp:coreProperties>` },
    { name: 'xl/workbook.xml', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>${sheets.map((s, i) => `<sheet name="${xml(s.name)}" sheetId="${i + 1}" r:id="rId${i + 1}"/>`).join('')}</sheets></workbook>` },
    { name: 'xl/_rels/workbook.xml.rels', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${sheets.map((_, i) => `<Relationship Id="rId${i + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet${i + 1}.xml"/>`).join('')}<Relationship Id="rId${sheets.length + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>` },
    { name: 'xl/styles.xml', data: styles },
    ...sheets.map((s, i) => ({ name: `xl/worksheets/sheet${i + 1}.xml`, data: wsFixed(s.xml) })),
  ];
  return zip(files);
}

// ---------------------------------------------------------------- DOCX
export function toDocx(model) {
  const run = (text, { b = false, sz = 18, color = '', i = false } = {}) =>
    `<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>${b ? '<w:b/>' : ''}${i ? '<w:i/>' : ''}${color ? `<w:color w:val="${color}"/>` : ''}<w:sz w:val="${sz}"/><w:szCs w:val="${sz}"/></w:rPr><w:t xml:space="preserve">${xml(text)}</w:t></w:r>`;
  const para = (runs, { after = 80, before = 0, align = '', keepNext = false } = {}) =>
    `<w:p><w:pPr>${keepNext ? '<w:keepNext/>' : ''}<w:spacing w:before="${before}" w:after="${after}"/>${align ? `<w:jc w:val="${align}"/>` : ''}</w:pPr>${runs}</w:p>`;
  const PAGE_W = 15840 - 1440; // landscape letter minus 0.5" margins (twips)
  const borders = '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="D5D9E0"/><w:left w:val="single" w:sz="4" w:color="D5D9E0"/><w:bottom w:val="single" w:sz="4" w:color="D5D9E0"/><w:right w:val="single" w:sz="4" w:color="D5D9E0"/><w:insideH w:val="single" w:sz="4" w:color="E5E7EB"/><w:insideV w:val="single" w:sz="4" w:color="E5E7EB"/></w:tblBorders>';
  const table = (cols, rows, totals) => {
    const total = cols.reduce((a, c) => a + (c.width || 12), 0);
    const widths = cols.map((c) => Math.floor(((c.width || 12) / total) * PAGE_W));
    const cell = (text, w, { header = false, shade = '', bold = false, right = false } = {}) =>
      `<w:tc><w:tcPr><w:tcW w:w="${w}" w:type="dxa"/>${shade ? `<w:shd w:val="clear" w:color="auto" w:fill="${shade}"/>` : ''}<w:tcMar><w:top w:w="40" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/></w:tcMar></w:tcPr>`
      + `<w:p><w:pPr><w:spacing w:before="0" w:after="0"/>${right ? '<w:jc w:val="right"/>' : ''}</w:pPr>${String(text).split('\n').map((ln, k) => (k ? '<w:r><w:br/></w:r>' : '') + run(ln, { b: header || bold, sz: 15, color: header ? 'FFFFFF' : '' })).join('')}</w:p></w:tc>`;
    const head = `<w:tr><w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>${cols.map((c, i) => cell(c.label, widths[i], { header: true, shade: '376EB8', right: c.type === 'money' || c.type === 'int' })).join('')}</w:tr>`;
    const body = rows.map((r, ri) => `<w:tr><w:trPr><w:cantSplit/></w:trPr>${r.map((v, i) => cell(cellText(v, cols[i].type), widths[i], { shade: ri % 2 ? 'F6F7FB' : '', right: cols[i].type === 'money' || cols[i].type === 'int' })).join('')}</w:tr>`).join('');
    const tot = totals ? `<w:tr>${totals.map((v, i) => cell(v === '' || v == null ? '' : cellText(v, cols[i].type), widths[i], { bold: true, shade: 'EEF2F7', right: cols[i].type === 'money' || cols[i].type === 'int' })).join('')}</w:tr>` : '';
    return `<w:tbl><w:tblPr><w:tblW w:w="${PAGE_W}" w:type="dxa"/>${borders}<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="70" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>${widths.map((w) => `<w:gridCol w:w="${w}"/>`).join('')}</w:tblGrid>${head}${body}${tot}</w:tbl>`;
  };
  const brand = run('TICKET', { b: true, sz: 30, color: '84D65F' }) + run('JUNK', { b: true, sz: 30, color: '376EB8' }) + run('IES', { b: true, sz: 30, color: 'EB742A' }) + run('.COM', { b: true, sz: 30, color: '376EB8' });
  let body = para(brand, { after: 60 })
    + para(run(model.title, { b: true, sz: 36 }), { after: 40 })
    + para(run(model.subtitle, { i: true, sz: 18, color: '6B7280' }), { after: 200 });
  if (model.meta?.length) {
    body += para(run('Summary', { b: true, sz: 24, color: '376EB8' }), { before: 120, after: 80, keepNext: true });
    body += table([{ label: 'Measure', width: 30 }, { label: 'Value', width: 50 }], model.meta.map(([k, v]) => [k, typeof v === 'number' && isMoneyKey(k) ? fmtMoney(v) : String(v)]));
  }
  for (const s of model.sections) {
    body += para(run(s.title, { b: true, sz: 24, color: '376EB8' }), { before: 280, after: 80, keepNext: true });
    body += s.rows.length ? table(s.columns, s.rows, s.totals) : para(run('No data for this period.', { i: true, color: '6B7280' }));
  }
  body += para(run(`Generated by TicketJunkies.com \u00b7 ${fmtDate(new Date())}`, { i: true, sz: 14, color: '9AA3B2' }), { before: 300 });
  const sect = '<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr>';
  const doc = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>${body}${sect}</w:body></w:document>`;
  return zip([
    { name: '[Content_Types].xml', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>` },
    { name: '_rels/.rels', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>` },
    { name: 'docProps/core.xml', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>${xml(model.title)}</dc:title><dc:creator>TicketJunkies.com</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">${new Date().toISOString().replace(/\.\d+Z$/, 'Z')}</dcterms:created></cp:coreProperties>` },
    { name: 'word/document.xml', data: doc },
  ]);
}

// ---------------------------------------------------------------- PDF
// Helvetica glyph widths (per 1000 em) for ASCII 32..126
const HW = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
const WIN = { '\u2022': 0x95, '\u2013': 0x96, '\u2014': 0x97, '\u2018': 0x91, '\u2019': 0x92, '\u201c': 0x93, '\u201d': 0x94, '\u2026': 0x85, '\u20ac': 0x80, '\u2122': 0x99, '\u2713': 0x76 };
function winAnsi(s) {
  const out = [];
  for (const ch of String(s ?? '')) {
    const c = ch.codePointAt(0);
    if (WIN[ch] != null && ch !== '\u2713') out.push(WIN[ch]);
    else if (ch === '\u2713') out.push(0x2b); // ✓ → +
    else if (c === 10 || c === 13 || c === 9) out.push(32);
    else if (c >= 32 && c <= 126) out.push(c);
    else if (c >= 160 && c <= 255) out.push(c);
    else if (c > 0xffff || (c >= 0x2600 && c <= 0x27bf) || c === 0xfe0f) continue; // emoji
    else out.push(0x3f);
  }
  return Buffer.from(out);
}
const glyphW = (b, bold) => (b >= 32 && b <= 126 ? HW[b - 32] : b === 0x95 ? 350 : b === 0x97 ? 1000 : b === 0x96 ? 556 : 556) * (bold ? 1.06 : 1);
const textW = (buf, size, bold) => { let w = 0; for (const b of buf) w += glyphW(b, bold); return (w * size) / 1000; };
const pdfStr = (buf) => '(' + [...buf].map((b) => (b === 0x28 || b === 0x29 || b === 0x5c ? '\\' + String.fromCharCode(b) : b < 32 || b > 126 ? '\\' + b.toString(8).padStart(3, '0') : String.fromCharCode(b))).join('') + ')';

function wrap(text, width, size, bold, maxLines = 3) {
  const buf = winAnsi(text);
  if (textW(buf, size, bold) <= width) return [buf];
  const words = buf.toString('latin1').split(/ +/);
  const lines = []; let cur = '';
  const fits = (s) => textW(Buffer.from(s, 'latin1'), size, bold) <= width;
  for (let w of words) {
    while (!fits(w) && w.length > 1) { // break very long words
      let k = w.length; while (k > 1 && !fits((cur ? cur + ' ' : '') + w.slice(0, k))) k--;
      if (k <= 1 && cur) { lines.push(cur); cur = ''; continue; }
      lines.push((cur ? cur + ' ' : '') + w.slice(0, k)); cur = ''; w = w.slice(k);
    }
    const t = cur ? cur + ' ' + w : w;
    if (fits(t)) cur = t; else { if (cur) lines.push(cur); cur = w; }
  }
  if (cur) lines.push(cur);
  if (lines.length > maxLines) {
    const keep = lines.slice(0, maxLines);
    let last = keep[maxLines - 1];
    while (last.length && !fits(last + '...')) last = last.slice(0, -1);
    keep[maxLines - 1] = last + '...';
    return keep.map((l) => Buffer.from(l, 'latin1'));
  }
  return lines.map((l) => Buffer.from(l, 'latin1'));
}

export function toPdf(model) {
  const W = 792, H = 612, M = 34, CW = W - 2 * M;
  const pages = []; let ops = []; let y = 0;
  const rgb = (hex) => [0, 2, 4].map((i) => (parseInt(hex.slice(i, i + 2), 16) / 255).toFixed(3)).join(' ');
  const text = (x, yy, buf, size, bold = false, color = '1B1F2A') => ops.push(`BT /${bold ? 'F2' : 'F1'} ${size} Tf ${rgb(color)} rg ${x.toFixed(2)} ${yy.toFixed(2)} Td ${pdfStr(buf)} Tj ET`);
  const rect = (x, yy, w, h, color) => ops.push(`${rgb(color)} rg ${x.toFixed(2)} ${yy.toFixed(2)} ${w.toFixed(2)} ${h.toFixed(2)} re f`);
  const line = (x1, y1, x2, y2, color = 'E5E7EB', w = 0.5) => ops.push(`${rgb(color)} RG ${w} w ${x1.toFixed(2)} ${y1.toFixed(2)} m ${x2.toFixed(2)} ${y2.toFixed(2)} l S`);
  const newPage = () => {
    if (ops.length) pages.push(ops);
    ops = [];
    // header band
    let x = M;
    for (const [t, c] of [['TICKET', '84D65F'], ['JUNK', '376EB8'], ['IES', 'EB742A'], ['.COM', '376EB8']]) { const b = winAnsi(t); text(x, H - M - 12, b, 13, true, c); x += textW(b, 13, true); }
    const sub = winAnsi(model.title); text(W - M - textW(sub, 8, false), H - M - 10, sub, 8, false, '6B7280');
    line(M, H - M - 20, W - M, H - M - 20, 'EB742A', 1.2);
    y = H - M - 34;
  };
  const ensure = (h) => { if (y - h < M + 18) { newPage(); return true; } return false; };

  newPage();
  text(M, y - 16, winAnsi(model.title), 18, true); y -= 24;
  text(M, y - 9, winAnsi(model.subtitle), 9, false, '6B7280'); y -= 22;

  // summary grid (4 per row)
  if (model.meta?.length) {
    const boxW = (CW - 3 * 8) / 4, boxH = 38;
    model.meta.forEach(([k, v], i) => {
      if (i % 4 === 0) { ensure(boxH + 8); if (i) y -= boxH + 8; }
      const x = M + (i % 4) * (boxW + 8);
      rect(x, y - boxH, boxW, boxH, 'F2F4F8');
      rect(x, y - 2.5, boxW, 2.5, ['376EB8', '84D65F', 'EB742A', '376EB8'][i % 4]);
      text(x + 8, y - 14, winAnsi(k), 7.5, false, '6B7280');
      const val = typeof v === 'number' && isMoneyKey(k) ? fmtMoney(v) : String(v);
      text(x + 8, y - 30, wrap(val, boxW - 16, 12, true, 1)[0], 12, true);
    });
    y -= boxH + 18;
  }

  const FS = 7.2, LH = 9, PAD = 3;
  for (const sec of model.sections) {
    const total = sec.columns.reduce((a, c) => a + (c.width || 12), 0);
    const widths = sec.columns.map((c) => ((c.width || 12) / total) * CW);
    const drawHead = () => {
      const hl = sec.columns.map((c, i) => wrap(c.label, widths[i] - 2 * PAD, FS, true, 2));
      const hh = Math.max(...hl.map((l) => l.length)) * LH + 2 * PAD;
      rect(M, y - hh, CW, hh, '376EB8');
      let x = M;
      hl.forEach((ls, i) => {
        const right = sec.columns[i].type === 'money' || sec.columns[i].type === 'int';
        ls.forEach((b, k) => text(right ? x + widths[i] - PAD - textW(b, FS, true) : x + PAD, y - PAD - (k + 1) * LH + 2, b, FS, true, 'FFFFFF'));
        x += widths[i];
      });
      y -= hh;
    };
    ensure(46);
    text(M, y - 12, winAnsi(sec.title), 12, true, '376EB8'); y -= 18;
    if (!sec.rows.length) { text(M, y - 9, winAnsi('No data for this period.'), 8.5, false, '6B7280'); y -= 22; continue; }
    drawHead();
    const all = sec.rows.map((r) => ({ r, total: false })).concat(sec.totals ? [{ r: sec.totals, total: true }] : []);
    all.forEach(({ r, total: isTot }, ri) => {
      const cells = r.map((v, i) => wrap(v === '' || v == null ? '' : cellText(v, sec.columns[i].type), widths[i] - 2 * PAD, FS, isTot, 3));
      const rh = Math.max(1, ...cells.map((l) => l.length)) * LH + 2 * PAD;
      if (ensure(rh)) { text(M, y - 10, winAnsi(sec.title + ' (continued)'), 9, true, '376EB8'); y -= 16; drawHead(); }
      if (isTot) rect(M, y - rh, CW, rh, 'EEF2F7');
      else if (ri % 2) rect(M, y - rh, CW, rh, 'F7F8FB');
      let x = M;
      cells.forEach((ls, i) => {
        const right = sec.columns[i].type === 'money' || sec.columns[i].type === 'int';
        ls.forEach((b, k) => text(right ? x + widths[i] - PAD - textW(b, FS, isTot) : x + PAD, y - PAD - (k + 1) * LH + 2, b, FS, isTot));
        x += widths[i];
      });
      y -= rh;
      line(M, y, M + CW, y);
    });
    y -= 16;
  }
  pages.push(ops);

  // assemble PDF
  const objs = [];
  const add = (s) => { objs.push(s); return objs.length; };
  const catalog = add(null); const pagesObj = add(null);
  const f1 = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
  const f2 = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
  const info = add(`<< /Title ${pdfStr(winAnsi(model.title))} /Producer (TicketJunkies.com) /CreationDate (D:${new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14)}) >>`);
  const kids = [];
  pages.forEach((p, i) => {
    const footer = [`BT /F1 7 Tf ${rgb('9AA3B2')} rg ${M} ${M - 14} Td ${pdfStr(winAnsi('TicketJunkies.com  \u00b7  ' + fmtDate(new Date())))} Tj ET`];
    const pg = winAnsi(`Page ${i + 1} of ${pages.length}`);
    footer.push(`BT /F1 7 Tf ${rgb('9AA3B2')} rg ${(W - M - textW(pg, 7)).toFixed(2)} ${M - 14} Td ${pdfStr(pg)} Tj ET`);
    const content = Buffer.from([...p, ...footer].join('\n'), 'latin1');
    const deflated = zlib.deflateSync(content);
    const cs = add({ stream: deflated, dict: `<< /Length ${deflated.length} /Filter /FlateDecode >>` });
    kids.push(add(`<< /Type /Page /Parent ${pagesObj} 0 R /MediaBox [0 0 ${W} ${H}] /Resources << /Font << /F1 ${f1} 0 R /F2 ${f2} 0 R >> >> /Contents ${cs} 0 R >>`));
  });
  objs[catalog - 1] = `<< /Type /Catalog /Pages ${pagesObj} 0 R >>`;
  objs[pagesObj - 1] = `<< /Type /Pages /Kids [${kids.map((k) => `${k} 0 R`).join(' ')}] /Count ${kids.length} >>`;
  const chunks = [Buffer.from('%PDF-1.4\n%\xe2\xe3\xcf\xd3\n', 'latin1')];
  let pos = chunks[0].length; const xref = [];
  objs.forEach((o, i) => {
    xref.push(pos);
    let b;
    if (o && o.stream) b = Buffer.concat([Buffer.from(`${i + 1} 0 obj\n${o.dict}\nstream\n`, 'latin1'), o.stream, Buffer.from('\nendstream\nendobj\n', 'latin1')]);
    else b = Buffer.from(`${i + 1} 0 obj\n${o}\nendobj\n`, 'latin1');
    chunks.push(b); pos += b.length;
  });
  const xr = `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n${xref.map((p) => String(p).padStart(10, '0') + ' 00000 n \n').join('')}trailer\n<< /Size ${objs.length + 1} /Root ${catalog} 0 R /Info ${info} 0 R >>\nstartxref\n${pos}\n%%EOF\n`;
  chunks.push(Buffer.from(xr, 'latin1'));
  return Buffer.concat(chunks);
}

// ---------------------------------------------------------------- TICKET PDF (one ticket per page, vector QR)
// order: { id, buyerName, tickets[{code, tierName, price, checkedIn}], comp, status }
// ev:    { title, startsAt, endsAt, venue, address, city }
// qrMatrix: function(text) -> boolean[][]
export function ticketsPdf(order, ev, qrMatrix, { site = '' } = {}) {
  const W = 612, H = 792;
  const rgb = (hex) => [0, 2, 4].map((i) => (parseInt(hex.slice(i, i + 2), 16) / 255).toFixed(3)).join(' ');
  const fmtWhen = (v) => (v ? new Date(v).toLocaleString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '');
  const pages = order.tickets.map((t, idx) => {
    const ops = [];
    const text = (x, y, s, size, bold = false, color = '1B1F2A') => ops.push(`BT /${bold ? 'F2' : 'F1'} ${size} Tf ${rgb(color)} rg ${x.toFixed(2)} ${y.toFixed(2)} Td ${pdfStr(winAnsi(s))} Tj ET`);
    const center = (y, s, size, bold, color, x0 = 0, w = W) => { const b = winAnsi(s); text(x0 + (w - textW(b, size, bold)) / 2, y, s, size, bold, color); };
    const rect = (x, y, w, h, color) => ops.push(`${rgb(color)} rg ${x.toFixed(2)} ${y.toFixed(2)} ${w.toFixed(2)} ${h.toFixed(2)} re f`);
    // ticket card
    const cx = 56, cw = W - 112, top = H - 56, ch = 600, bottom = top - ch;
    ops.push(`${rgb('EB742A')} RG 2 w [6 4] 0 d ${cx} ${bottom} ${cw} ${ch} re S [] 0 d`);
    rect(cx, top - 70, cw, 70, '1B1F2A');
    // brand
    let bx = cx + 24;
    for (const [s, c] of [['TICKET', '84D65F'], ['JUNK', '6FA0E6'], ['IES', 'EB742A'], ['.COM', '6FA0E6']]) { const b = winAnsi(s); text(bx, top - 44, s, 22, true, c); bx += textW(b, 22, true); }
    const tn = `Ticket ${idx + 1} of ${order.tickets.length}`; text(cx + cw - 24 - textW(winAnsi(tn), 11, false), top - 41, tn, 11, false, 'CFD5DF');
    // tier
    center(top - 112, String(t.tierName || 'Admission').toUpperCase(), 14, true, 'EB742A', cx, cw);
    // title (wrapped, max 2 lines)
    const lines = wrap(ev.title || 'Event', cw - 60, 24, true, 2);
    let y = top - 146;
    for (const l of lines) { center(y, l.toString('latin1'), 24, true, '1B1F2A', cx, cw); y -= 30; }
    center(y - 2, fmtWhen(ev.startsAt), 12.5, false, '374151', cx, cw);
    center(y - 20, [ev.venue, ev.address, ev.city].filter(Boolean).join(', ').slice(0, 90), 11.5, false, '6B7280', cx, cw);
    // QR
    const m = qrMatrix(t.code); const n = m.length; const size = 250; const mod = size / (n + 8);
    const qx = (W - size) / 2, qy = y - 52 - size;
    rect(qx, qy, size, size, 'FFFFFF');
    ops.push(`${rgb('000000')} rg`);
    m.forEach((row, r) => row.forEach((dark, c) => { if (dark) ops.push(`${(qx + (c + 4) * mod).toFixed(2)} ${(qy + size - (r + 5) * mod).toFixed(2)} ${(mod + 0.05).toFixed(2)} ${(mod + 0.05).toFixed(2)} re`); }));
    ops.push('f');
    // code
    const codeY = qy - 34;
    const cb = winAnsi(t.code); ops.push(`BT /F3 22 Tf ${rgb('376EB8')} rg ${((W - cb.length * 13.2) / 2).toFixed(2)} ${codeY} Td ${pdfStr(cb)} Tj ET`);
    // details
    ops.push(`${rgb('E5E7EB')} RG 1 w ${cx + 30} ${codeY - 22} m ${cx + cw - 30} ${codeY - 22} l S`);
    const details = [['Attendee', t.holderName || order.buyerName], ['Order #', order.id.slice(-8).toUpperCase()], ['Price', order.comp ? 'Complimentary' : (t.price ? `$${Number(t.price).toFixed(2)}` : 'Free')]];
    const colW = (cw - 60) / 3;
    details.forEach(([k, v], i) => { const x0 = cx + 30 + i * colW; center(codeY - 44, k.toUpperCase(), 8.5, true, '9AA3B2', x0, colW); center(codeY - 60, String(v).slice(0, 28), 12, true, '1B1F2A', x0, colW); });
    if (order.status === 'refunded') { center(codeY - 90, 'REFUNDED - THIS TICKET IS NOT VALID', 13, true, 'C62828', cx, cw); }
    else if (t.checkedIn) { center(codeY - 90, 'Already checked in', 11, true, '3E7F22', cx, cw); }
    // footer
    center(bottom - 26, 'Show this QR code at the door - on your phone or printed. Each ticket admits one person and can be scanned once.', 9, false, '6B7280');
    if (site) center(bottom - 40, `Your tickets are always in your dashboard: ${site}/#/tickets`, 9, false, '6B7280');
    return ops.join('\n');
  });

  const objs = []; const add = (s) => { objs.push(s); return objs.length; };
  const catalog = add(null); const pagesObj = add(null);
  const f1 = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
  const f2 = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
  const f3 = add('<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>');
  const info = add(`<< /Title ${pdfStr(winAnsi('Tickets - ' + (ev.title || '')))} /Producer (TicketJunkies.com) >>`);
  const kids = pages.map((content) => {
    const d = zlib.deflateSync(Buffer.from(content, 'latin1'));
    const cs = add({ stream: d, dict: `<< /Length ${d.length} /Filter /FlateDecode >>` });
    return add(`<< /Type /Page /Parent ${pagesObj} 0 R /MediaBox [0 0 ${W} ${H}] /Resources << /Font << /F1 ${f1} 0 R /F2 ${f2} 0 R /F3 ${f3} 0 R >> >> /Contents ${cs} 0 R >>`);
  });
  objs[catalog - 1] = `<< /Type /Catalog /Pages ${pagesObj} 0 R >>`;
  objs[pagesObj - 1] = `<< /Type /Pages /Kids [${kids.map((k) => `${k} 0 R`).join(' ')}] /Count ${kids.length} >>`;
  const chunks = [Buffer.from('%PDF-1.4\n%\xe2\xe3\xcf\xd3\n', 'latin1')]; let pos = chunks[0].length; const xref = [];
  objs.forEach((o, i) => {
    xref.push(pos);
    const b = o && o.stream ? Buffer.concat([Buffer.from(`${i + 1} 0 obj\n${o.dict}\nstream\n`, 'latin1'), o.stream, Buffer.from('\nendstream\nendobj\n', 'latin1')]) : Buffer.from(`${i + 1} 0 obj\n${o}\nendobj\n`, 'latin1');
    chunks.push(b); pos += b.length;
  });
  chunks.push(Buffer.from(`xref\n0 ${objs.length + 1}\n0000000000 65535 f \n${xref.map((p) => String(p).padStart(10, '0') + ' 00000 n \n').join('')}trailer\n<< /Size ${objs.length + 1} /Root ${catalog} 0 R /Info ${info} 0 R >>\nstartxref\n${pos}\n%%EOF\n`, 'latin1'));
  return Buffer.concat(chunks);
}
