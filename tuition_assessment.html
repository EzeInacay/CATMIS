<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Tuition Assessment | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
/* ===== GLOBAL ===== */
body { margin:0; font-family:Arial; background:#eef1f4; }
.sidebar {
    width:250px; height:100vh; position:fixed;
    background:linear-gradient(to bottom,#0f2027,#203a43);
    color:white; display:flex; flex-direction:column;
}
.sidebar-header { padding:25px; border-bottom:1px solid rgba(255,255,255,.1); }
.sidebar ul { list-style:none; padding:0; margin:20px 0; }
.sidebar ul li { padding:12px 25px; }
.sidebar ul li a { color:white; text-decoration:none; display:block; }
.sidebar ul li:hover { background:rgba(255,255,255,.1); }
.active { background:rgba(255,255,255,.15); }
.logout-btn { background:#ff3b30; border:none; color:white; padding:12px; width:100%; cursor:pointer; margin-top:auto; }
.main { margin-left:250px; padding:40px; }

.card {
    background:white; padding:25px;
    border-radius:12px;
    box-shadow:0 4px 10px rgba(0,0,0,.05);
    margin-bottom:30px;
}
input, select {
    padding:10px; width:100%;
    margin-bottom:15px;
}
button.primary {
    background:#0077b6; color:white;
    border:none; padding:10px 15px;
    border-radius:6px; cursor:pointer;
}
table {
    width:100%;
    border-collapse:collapse;
    background:white;
    border-radius:10px;
    overflow:hidden;
    box-shadow:0 4px 10px rgba(0,0,0,.05);
}

th, td {
    padding:10px;
    border-bottom:1px solid #eee;
    text-align:center;
}

th {
    background:#f2f4f7;
}
</style>
</head>

<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>CATMIS</h2>
        <p>CCS Portal</p>
    </div>
    <ul>
        <li><a href="admin_dashboard.html">🏠 Admin Dashboard</a></li>
        <li class="active"><a href="#">📂 Tuition Assessment</a></li>
        <li><a href="user_management.html">👥 User Management</a></li>
        <li><a href="payment_history.html">📄 Payment History</a></li>
        <li><a href="audit_logs.html">🕒 Audit Logs</a></li>
    </ul>
    <button class="logout-btn" onclick="logout()">Logout</button>
</div>

<div class="main">
    <h2>Tuition Assessment</h2>

    <div class="card">
        <h3>Tuition Matrix</h3>
        <div id="tuitionContainer"></div>
    </div>
</div>

<div id="feeModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;
background:rgba(0,0,0,0.5);">

<div style="background:white;padding:20px;width:400px;margin:100px auto;border-radius:10px;">
    <h3 id="feeTitle"></h3>

    <table id="feeTable"></table>

    <button onclick="addFeeRow()">➕ Add Fee</button>
    <button onclick="closeModal()">Close</button>
</div>
</div>

<script>
let currentGroup = "";

function openFeeEditor(group){

currentGroup = group;

document.getElementById("feeModal").style.display = "block";
document.getElementById("feeTitle").innerText = group + " Fee Structure";

renderFeeEditor();

}

function renderFeeEditor(){

let table = document.getElementById("feeTable");

table.innerHTML = `
<thead>
<tr>
<th>Fee Name</th>
<th>Amount</th>
<th>Action</th>
</tr>
</thead>
<tbody></tbody>
`;

let tbody = table.querySelector("tbody");

tuitionData[currentGroup].fees.forEach((fee, index) => {

let row = tbody.insertRow();

row.innerHTML = `
<td contenteditable="true">${fee.name}</td>
<td contenteditable="true">${fee.amount}</td>
<td>
<button onclick="saveFee(${index}, this)">💾</button>
<button onclick="deleteFee(${index})">🗑</button>
</td>
`;

});

}

let defaultData = {
    "G1-3": {
        fees: [
            { name: "Tuition", amount: 20000 },
            { name: "Registration Fee", amount: 2000 }
        ],
        sections: []
    },
    "G4-6": { fees: [], sections: [] },
    "G7-10": { fees: [], sections: [] },
    "G11-12": { fees: [], sections: [] }
};

let stored = JSON.parse(localStorage.getItem("tuitionData"));

let tuitionData = JSON.parse(localStorage.getItem("tuitionData")) || {
    "G1-3": {
        fees: [
            { name: "Tuition", sy2022: 15000, sy2023: 15000, sy2024: 15000 },
            { name: "Registration Fee", sy2022: 500, sy2023: 500, sy2024: 500 },
            { name: "Testing", sy2022: 400, sy2023: 400, sy2024: 400 },
            { name: "Instructional Resources", sy2022: 1000, sy2023: 1400, sy2024: 1400 },
            { name: "Memberships", sy2022: 100, sy2023: 100, sy2024: 100 },
            { name: "Lunch Program", sy2022: 0, sy2023: 400, sy2024: 400 },
            { name: "Athletics", sy2022: 0, sy2023: 100, sy2024: 100 },
            { name: "Library Fee", sy2022: 0, sy2023: 100, sy2024: 100 },
            { name: "Energy & Communication", sy2022: 500, sy2023: 500, sy2024: 500 }
        ],
        sections: []
    },

    "G4-6": { fees: [], sections: [] },
    "G7-10": { fees: [], sections: [] },
    "G11-12": { fees: [], sections: [] }
};

let tuitionRules = JSON.parse(localStorage.getItem("tuitionRules")) || [
    { grade: "10", section: "", amount: 20000 },
    { grade: "11", section: "STEM-A", amount: 30000 },
    { grade: "11", section: "ABM-B", amount: 28000 }
];

function renderTables(){

let container = document.getElementById("tuitionContainer");
container.innerHTML = "";

Object.keys(tuitionData).forEach(group => {

let groupDiv = document.createElement("div");

let fees = tuitionData[group].fees;

// compute totals
let total2022 = 0;
let total2023 = 0;
let total2024 = 0;

fees.forEach(f => {
    total2022 += Number(f.sy2022 || 0);
    total2023 += Number(f.sy2023 || 0);
    total2024 += Number(f.sy2024 || 0);
});

let table = document.createElement("table");

table.innerHTML = `
<thead>
<tr>
<th rowspan="2">GRADE LEVEL</th>
<th rowspan="2">Fee Type</th>
<th>SY2022-2023</th>
<th>SY2023-2024</th>
<th>SY2024-2025</th>
</tr>
</thead>
<tbody id="${group}"></tbody>
`;

let tbody = table.querySelector("tbody");

// rows (vertical fees)
fees.forEach((fee, index) => {

let row = tbody.insertRow();

row.innerHTML = `
<td>${index === 0 ? group : ""}</td>
<td contenteditable="true">${fee.name}</td>
<td contenteditable="true">${fee.sy2022}</td>
<td contenteditable="true">${fee.sy2023}</td>
<td contenteditable="true">${fee.sy2024}</td>
`;

});

// TOTAL ROW
let totalRow = tbody.insertRow();

totalRow.innerHTML = `
<td></td>
<td><strong>TOTAL</strong></td>
<td><strong>${total2022}</strong></td>
<td><strong>${total2023}</strong></td>
<td><strong>${total2024}</strong></td>
`;

// HEADER + BUTTONS
let header = document.createElement("h3");
header.innerHTML = `
${group}
<button class="primary" onclick="openFeeEditor('${group}')">✏️ Edit Tuition</button>
`;

groupDiv.appendChild(header);
groupDiv.appendChild(table);

container.appendChild(groupDiv);

});

}

function calculateTotal(fees){
if(!fees || !Array.isArray(fees)) return 0;

return fees.reduce((sum, f) => sum + Number(f.amount || 0), 0);
}

function addSection(group){

let section = prompt("Enter Section (e.g. STEM-A):");
if(!section) return;

tuitionData[group].sections.push(section.toUpperCase());

saveData();
renderTables();

}
function addFeeRow(){

tuitionData[currentGroup].fees.push({
    name: "New Fee",
    amount: 0
});

saveData();
renderFeeEditor();

}

function saveFee(index, btn){

let row = btn.parentNode.parentNode;

let name = row.cells[0].textContent;
let sy2022 = Number(row.cells[1].textContent);
let sy2023 = Number(row.cells[2].textContent);
let sy2024 = Number(row.cells[3].textContent);

tuitionData[currentGroup].fees[index] = {
    name, sy2022, sy2023, sy2024
};

saveData();
renderTables();

}

function deleteFee(index){

if(confirm("Delete this fee?")){
    tuitionData[currentGroup].fees.splice(index,1);

    saveData();
    renderFeeEditor();
    renderTables();
}

}

function getStudentTuition(grade){

let group = "";

if(grade >=1 && grade <=3) group = "G1-3";
else if(grade <=6) group = "G4-6";
else if(grade <=10) group = "G7-10";
else group = "G11-12";

return calculateTotal(tuitionData[group].fees);

}

function closeModal(){
document.getElementById("feeModal").style.display = "none";
}

function saveData(){
localStorage.setItem("tuitionData", JSON.stringify(tuitionData));
}

if (localStorage.getItem("role") !== "admin") {
    window.location.href = "login.html";
}
function logout() {
    localStorage.removeItem("role");
    window.location.href = "login.html";
}

window.onload = renderTables;
</script>

</body>
</html>
