// Minimal UI helpers
document.addEventListener('DOMContentLoaded', () => {
  document.documentElement.classList.add('mounted');
  // Ensure dark mode class present for Tailwind dark mode strategy
  try { document.documentElement.classList.add('dark'); } catch {}

  // Inject Tailwind CDN with config mapping existing CSS variables/colors
  try {
    const hasTailwind = !!document.querySelector('script[data-tailwind-cdn]');
    if (!hasTailwind) {
      // Config must be defined before the CDN script loads
      const cfg = document.createElement('script');
      cfg.type = 'text/javascript';
      cfg.text = `
        window.tailwind = window.tailwind || {};
        tailwind.config = {
          darkMode: 'class',
          corePlugins: { preflight: false },
          theme: {
            extend: {
              colors: {
                brand: {
                  bg: '#d32f2f',
                  text: '#ffffff'
                },
                mc: {
                  bg: '#121218',
                  card: '#1e1e24',
                  text: '#e0e0e0',
                  muted: '#8b8b9a',
                  border: '#33333d',
                  borderHover: '#4a4a58',
                  focus: '#5a93ff',
                  green: '#58d68d',
                  red: '#e74c3c',
                  blue: '#4fc3f7',
                }
              },
              borderRadius: { sm: '8px', md: '12px', lg: '16px' }
            }
          }
        };
      `;
      document.head.appendChild(cfg);

      const s = document.createElement('script');
      s.src = 'https://cdn.tailwindcss.com';
      s.async = true;
      s.setAttribute('data-tailwind-cdn', 'true');
      document.head.appendChild(s);
    }
  } catch {}
});

// Simple table sort by clicking headers
// Usage: add data-sort="number" or data-sort="text" on <th>
function enableTableSort(tableSelector) {
  const table = document.querySelector(tableSelector);
  if (!table) return;
  const tbody = table.tBodies[0];
  if (!tbody) return;
  const getCellValue = (row, idx) => row.children[idx]?.innerText?.trim() ?? '';
  const compare = (a, b, type, dir) => {
    if (type === 'number') {
      const na = parseFloat(a.replace(/[^0-9.-]/g, '')) || 0;
      const nb = parseFloat(b.replace(/[^0-9.-]/g, '')) || 0;
      return dir * (na - nb);
    }
    return dir * a.localeCompare(b, undefined, { sensitivity: 'base' });
  };
  table.querySelectorAll('thead th').forEach((th, idx) => {
    const type = th.dataset.sort || 'text';
    th.style.cursor = 'pointer';
    let dir = 1;
    th.addEventListener('click', () => {
      const rows = Array.from(tbody.querySelectorAll('tr')).sort((r1, r2) => {
        const a = getCellValue(r1, idx);
        const b = getCellValue(r2, idx);
        return compare(a, b, type, dir);
      });
      // toggle dir for next click
      dir = -dir;
      rows.forEach(r => tbody.appendChild(r));
    });
  });
}

window.enableTableSort = enableTableSort;

// Simple table search (filters rows by substring match across all cells)
function enableTableSearch(tableSelector, inputSelector) {
  const table = document.querySelector(tableSelector);
  const input = document.querySelector(inputSelector);
  if (!table || !input) return;
  const tbody = table.tBodies[0];
  if (!tbody) return;
  const rows = Array.from(tbody.querySelectorAll('tr'));
  input.addEventListener('input', () => {
    const q = input.value.toLowerCase();
    rows.forEach(row => {
      const txt = row.innerText.toLowerCase();
      row.style.display = txt.includes(q) ? '' : 'none';
    });
  });
}

window.enableTableSearch = enableTableSearch;

// Simple table pagination
function enableTablePagination(tableSelector, options = {}) {
  const table = typeof tableSelector === 'string' ? document.querySelector(tableSelector) : tableSelector;
  if (!table) return;
  const tbody = table.tBodies[0];
  if (!tbody) return;
  const rows = Array.from(tbody.querySelectorAll('tr'));
  if (rows.length === 0) return;
  const pageSizes = options.pageSizes || [10, 25, 50, 100];
  let pageSize = options.pageSize || 25;
  let page = 1;

  // Build controls
  const pager = document.createElement('div');
  pager.className = 'pager';
  const left = document.createElement('div'); left.className = 'left';
  const right = document.createElement('div'); right.className = 'right';
  const label = document.createElement('span'); label.textContent = 'Rows per page:';
  const select = document.createElement('select');
  pageSizes.forEach(sz => { const opt = document.createElement('option'); opt.value = sz; opt.textContent = sz; if (sz === pageSize) opt.selected = true; select.appendChild(opt); });
  left.append(label, select);
  const btnPrev = document.createElement('button'); btnPrev.textContent = 'Prev';
  const info = document.createElement('span'); info.className = 'info';
  const btnNext = document.createElement('button'); btnNext.textContent = 'Next';
  right.append(btnPrev, info, btnNext);
  pager.append(left, right);

  table.insertAdjacentElement('afterend', pager);

  function render() {
    const total = rows.length;
    const pages = Math.max(1, Math.ceil(total / pageSize));
    if (page > pages) page = pages;
    const startIdx = (page - 1) * pageSize;
    const endIdx = Math.min(total, startIdx + pageSize);
    rows.forEach((tr, i) => { tr.style.display = (i >= startIdx && i < endIdx) ? '' : 'none'; });
    info.textContent = `${startIdx + 1}–${endIdx} dari ${total}`;
    btnPrev.disabled = page <= 1; btnNext.disabled = page >= pages;
  }

  select.addEventListener('change', () => { pageSize = parseInt(select.value, 10) || 25; page = 1; render(); });
  btnPrev.addEventListener('click', () => { if (page > 1) { page--; render(); } });
  btnNext.addEventListener('click', () => { page++; render(); });

  render();
}

function enableAutoPaging(opts = {}) {
  const selector = opts.selector || 'table';
  const table = document.querySelector(selector);
  if (table) enableTablePagination(table, opts);
}

window.enableTablePagination = enableTablePagination;
window.enableAutoPaging = enableAutoPaging;
