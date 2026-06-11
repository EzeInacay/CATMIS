/**
 * CATMIS — Export Preview Modal
 * Call previewAndExport(wb, filename) instead of XLSX.writeFile(wb, filename).
 */

(function () {
  const CSS = `
    #_exportPreviewOverlay {
      display: none; position: fixed; inset: 0; z-index: 99999;
      background: rgba(15,23,42,.55); backdrop-filter: blur(3px);
      align-items: center; justify-content: center;
    }
    #_exportPreviewOverlay.open { display: flex; }
    #_exportPreviewBox {
      background: #fff; border-radius: 14px; width: min(92vw, 900px);
      max-height: 88vh; display: flex; flex-direction: column;
      box-shadow: 0 24px 60px rgba(0,0,0,.22);
      font-family: inherit; overflow: hidden;
      animation: _epSlideIn .22s ease;
    }
    @keyframes _epSlideIn {
      from { opacity:0; transform: translateY(18px) scale(.97); }
      to   { opacity:1; transform: translateY(0)   scale(1);    }
    }
    #_exportPreviewBox ._ep-head {
      padding: 18px 22px 14px; border-bottom: 1px solid #e2e8f0;
      display: flex; align-items: center; gap: 10px;
    }
    #_exportPreviewBox ._ep-head h3 {
      margin:0; font-size:16px; font-weight:700; color:#0f172a; flex:1;
    }
    #_exportPreviewBox ._ep-head ._ep-filename {
      font-size:12px; color:#64748b; background:#f1f5f9;
      padding:3px 10px; border-radius:20px; font-family:monospace;
    }
    #_exportPreviewBox ._ep-notice {
      padding: 8px 22px; background:#fffbeb;
      border-bottom:1px solid #fde68a; font-size:12px; color:#92400e;
    }
    #_exportPreviewBox ._ep-scroll { flex:1; overflow:auto; padding:14px 22px; }
    #_exportPreviewBox ._ep-table {
      border-collapse: collapse; width:100%; font-size:12.5px; white-space: nowrap;
    }
    #_exportPreviewBox ._ep-table thead tr { background:#0f172a; color:#fff; }
    #_exportPreviewBox ._ep-table thead th {
      padding: 8px 12px; font-weight:600; text-align:left; position: sticky; top:0;
    }
    #_exportPreviewBox ._ep-table tbody tr:nth-child(even) { background: #f8fafc; }
    #_exportPreviewBox ._ep-table tbody td {
      padding: 6px 12px; color:#334155; border-bottom:1px solid #e2e8f0;
    }
    #_exportPreviewBox ._ep-table tbody tr:hover td { background:#eff6ff; }
    #_exportPreviewBox ._ep-footer {
      padding:14px 22px; border-top:1px solid #e2e8f0;
      display:flex; align-items:center; justify-content:space-between; gap:10px;
    }
    #_exportPreviewBox ._ep-footer ._ep-rowcount { font-size:12px; color:#64748b; }
    #_exportPreviewBox ._ep-footer ._ep-btns { display:flex; gap:10px; }
    #_exportPreviewBox ._ep-footer button {
      padding:9px 20px; border-radius:7px; font-size:14px;
      font-weight:600; cursor:pointer; border:none; font-family:inherit;
      transition: background .15s, transform .1s;
    }
    #_exportPreviewBox ._ep-footer button:active { transform:scale(.97); }
    #_exportPreviewBox ._ep-footer ._ep-cancel { background:#f1f5f9; color:#475569; }
    #_exportPreviewBox ._ep-footer ._ep-cancel:hover { background:#e2e8f0; }
    #_exportPreviewBox ._ep-footer ._ep-confirm { background:#16a34a; color:#fff; }
    #_exportPreviewBox ._ep-footer ._ep-confirm:hover { background:#15803d; }
  `;

  const HTML = `
    <div id="_exportPreviewOverlay">
      <div id="_exportPreviewBox">
        <div class="_ep-head">
          <span style="font-size:20px">📊</span>
          <h3>Preview Export</h3>
          <span class="_ep-filename" id="_epFilename"></span>
        </div>
        <div class="_ep-notice" id="_epNotice"></div>
        <div class="_ep-scroll">
          <table class="_ep-table" id="_epTable">
            <thead id="_epThead"></thead>
            <tbody id="_epTbody"></tbody>
          </table>
        </div>
        <div class="_ep-footer">
          <span class="_ep-rowcount" id="_epRowCount"></span>
          <div class="_ep-btns">
            <button class="_ep-cancel" id="_epCancelBtn">Cancel</button>
            <button class="_ep-confirm" id="_epConfirmBtn">✅ Confirm &amp; Download</button>
          </div>
        </div>
      </div>
    </div>
  `;

  let _pendingWb = null;
  let _pendingFilename = null;

  function _closePreview() {
    document.getElementById('_exportPreviewOverlay').classList.remove('open');
    _pendingWb = null;
    _pendingFilename = null;
  }

  function _init() {
    if (document.getElementById('_exportPreviewOverlay')) return; // already done

    // Inject styles
    const style = document.createElement('style');
    style.id = '_exportPreviewStyles';
    style.textContent = CSS;
    document.head.appendChild(style);

    // Inject modal HTML
    document.body.insertAdjacentHTML('beforeend', HTML);

    // Wire close buttons
    document.getElementById('_epCancelBtn').addEventListener('click', _closePreview);
    document.getElementById('_exportPreviewOverlay').addEventListener('click', function (e) {
      if (e.target === this) _closePreview();
    });
  }

  window.previewAndExport = function (wb, filename) {
    // Ensure DOM is ready before trying to show the modal
    _init();

    _pendingWb       = wb;
    _pendingFilename = filename;

    const firstSheetName = wb.SheetNames[0];
    const ws  = wb.Sheets[firstSheetName];
    const aoa = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });

    if (!aoa || aoa.length === 0) { alert('No data to preview.'); return; }

    const PREVIEW_LIMIT = 100;
    const headers    = aoa[0] || [];
    const dataRows   = aoa.slice(1);
    const totalRows  = dataRows.length;
    const previewRows = dataRows.slice(0, PREVIEW_LIMIT);

    document.getElementById('_epThead').innerHTML =
      '<tr>' + headers.map(h => `<th>${_esc(String(h))}</th>`).join('') + '</tr>';

    document.getElementById('_epTbody').innerHTML = previewRows.map(row =>
      '<tr>' + headers.map((_, ci) => `<td>${_esc(String(row[ci] ?? ''))}</td>`).join('') + '</tr>'
    ).join('');

    document.getElementById('_epFilename').textContent = filename;
    document.getElementById('_epRowCount').textContent =
      `${totalRows.toLocaleString()} data row${totalRows !== 1 ? 's' : ''}` +
      (totalRows > PREVIEW_LIMIT ? ` — showing first ${PREVIEW_LIMIT}` : '');

    const notice = document.getElementById('_epNotice');
    if (wb.SheetNames.length > 1) {
      notice.textContent = `ℹ️ Previewing sheet "${firstSheetName}" (${wb.SheetNames.length} sheets total: ${wb.SheetNames.join(', ')}).`;
      notice.style.display = '';
    } else {
      notice.style.display = 'none';
    }

    // Re-wire confirm button to avoid stacking listeners
    const confirmBtn = document.getElementById('_epConfirmBtn');
    const newBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
    newBtn.addEventListener('click', function () {
      XLSX.writeFile(_pendingWb, _pendingFilename);
      _closePreview();
    });

    document.getElementById('_exportPreviewOverlay').classList.add('open');
  };

  function _esc(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
})();