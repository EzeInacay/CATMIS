const table        = document.getElementById('studentTable');
const allRows      = Array.from(table.querySelectorAll('tbody tr'));
let currentGrade   = 'all';
let currentSection = 'all';

function sortAlphabetically() {
    const tbody   = table.querySelector('tbody');
    const visible = allRows.filter(r => r.style.display !== 'none');
    visible.sort((a, b) => a.cells[1].textContent.localeCompare(b.cells[1].textContent));
    visible.forEach(r => tbody.appendChild(r));
}
function searchTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    allRows.forEach(row => {
        const name    = row.cells[1].textContent.toLowerCase();
        const section = row.cells[3].textContent.toLowerCase();
        const gradeOk = currentGrade === 'all' || row.dataset.grade === currentGrade;
        const secOk   = currentSection === 'all' || row.dataset.section === currentSection;
        row.style.display = (name.includes(q) || section.includes(q)) && gradeOk && secOk ? '' : 'none';
    });
}
function filterGrade(grade) {
    currentGrade   = grade;
    currentSection = 'all';
    document.getElementById('sectionButtons').innerHTML = '';
    const sections = new Set();
    allRows.forEach(row => {
        const match = grade === 'all' || row.dataset.grade === grade;
        row.style.display = match ? '' : 'none';
        if (match) sections.add(row.dataset.section);
    });
    generateSectionButtons(sections);
    sortAlphabetically();
}
function generateSectionButtons(sections) {
    const container = document.getElementById('sectionButtons');
    container.innerHTML = '';
    if (sections.size === 0) return;
    const allBtn = document.createElement('button');
    allBtn.textContent = 'All Sections';
    allBtn.classList.add('active-sec');
    allBtn.onclick = () => filterSection('all', allBtn);
    container.appendChild(allBtn);
    [...sections].sort().forEach(sec => {
        const btn = document.createElement('button');
        btn.textContent = sec;
        btn.onclick = () => filterSection(sec, btn);
        container.appendChild(btn);
    });
}
function filterSection(section, btn) {
    currentSection = section;
    document.querySelectorAll('#sectionButtons button').forEach(b => b.classList.remove('active-sec'));
    if (btn) btn.classList.add('active-sec');
    allRows.forEach(row => {
        const gradeOk = currentGrade === 'all' || row.dataset.grade === currentGrade;
        const secOk   = section === 'all' || row.dataset.section === section;
        row.style.display = gradeOk && secOk ? '' : 'none';
    });
    sortAlphabetically();
}
function exportToExcel() {
    const headers = ['Student ID', 'Student Name', 'Grade', 'Section', 'Remaining Balance', 'Status'];
    const data    = [headers];
    allRows.filter(r => r.style.display !== 'none').forEach(r => {
        data.push([r.cells[0].textContent.trim(), r.cells[1].textContent.trim(),
            r.cells[2].textContent.trim(), r.cells[3].textContent.trim(),
            r.cells[4].textContent.trim(), r.cells[5].textContent.trim()]);
    });
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{ wch: 12 }, { wch: 28 }, { wch: 10 }, { wch: 16 }, { wch: 20 }, { wch: 12 }];
    XLSX.utils.book_append_sheet(wb, ws, 'Student Ledger');
    XLSX.writeFile(wb, `CATMIS_StudentLedger_${new Date().toISOString().slice(0, 10)}.xlsx`);
}
function dismissPopup() {
    const box = document.getElementById('popupBox');
    if (box) box.classList.add('hidden');
}
function pay(account_id) { window.location.href = 'payment_form.php?account_id=' + account_id; }
function logout() { window.location.href = 'logout.php'; }
window.onload = () => sortAlphabetically();