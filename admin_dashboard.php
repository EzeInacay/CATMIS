<?php
include 'php/config.php';
include 'php/get_balance.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Finance & Assessment Dashboard | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #eef1f4;
}

/* ===== SIDEBAR ===== */
.sidebar {
    width: 250px;
    height: 100vh;
    position: fixed;
    background: linear-gradient(to bottom, #0f2027, #203a43);
    color: white;
    display: flex;
    flex-direction: column;
}

.sidebar-header {
    padding: 25px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.sidebar-header h2 {
    margin: 0;
}

.sidebar-header p {
    margin: 5px 0 0;
    font-size: 14px;
    opacity: 0.8;
}

.sidebar ul {
    list-style: none;
    padding: 0;
    margin: 20px 0;
}

.sidebar ul li {
    padding: 12px 25px;
}

.sidebar ul li a {
    color: white;
    text-decoration: none;
    display: block;
}

.sidebar ul li:hover {
    background: rgba(255,255,255,0.1);
    cursor: pointer;
}

.active {
    background: rgba(255,255,255,0.15);
}

.system-notif {
    margin-top: auto;
    padding: 20px 25px;
    font-size: 14px;
}

.system-notif span {
    color: #ff3b30;
}

.logout-btn {
    background: #ff3b30;
    border: none;
    color: white;
    padding: 12px;
    width: 100%;
    cursor: pointer;
}

/*Search box*/
.search-box{
    margin-top:20px;
}

.search-box input{
    width:500px;
    padding:10px;
    border-radius:6px;
    border:1px solid #ccc;
}

.grade-buttons{
    margin-top:15px;
}

.grade-buttons button{
    padding:8px 15px;
    margin-right:10px;
    border:none;
    background:#0077b6;
    color:white;
    border-radius:6px;
    cursor:pointer;
}

.section-buttons{
    margin-top:10px;
}

.section-buttons button{
    padding:7px 12px;
    margin-right:8px;
    border:none;
    background:#6c757d;
    color:white;
    border-radius:6px;
    cursor:pointer;
}

/* ===== MAIN CONTENT ===== */
.main {
    margin-left: 250px;
    padding: 30px;
}

.title {
    font-size: 26px;
    font-weight: bold;
}

.priority-bar {
    background: #ff3b30;
    height: 18px;
    width: 400px;
    border-radius: 20px;
    margin: 20px 0;
    position: relative;
}

.priority-count {
    position: absolute;
    left: 10px;
    top: -2px;
    color: white;
    font-size: 12px;
    font-weight: bold;
}

/* ===== CARDS ===== */
.cards {
    display: flex;
    gap: 20px;
    margin-top: 20px;
}

.card {
    flex: 1;
    background: white;
    padding: 10px;
    border-radius: 10px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    border-left: 6px solid #0077b6;
}

.card h4 {
    margin: 0 0 8px;
    color: #6c757d;
}

.card p {
    font-size: 26px;
    margin: 0;
    font-weight: bold;
}

/* ===== TABLE ===== */
.table-container {
    margin-top: 40px;
    background: white;
    padding: 10px;
    border-radius: 12px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);

    max-height: 400px;   /* controls height */
    overflow-y: auto;    /* enables vertical scroll */
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    padding: 15px;
    text-align: left;
}

th {
    background: #f2f4f7;
}

tr:nth-child(even) {
    background: #fafafa;
}

.btn-payment {
    background: #0077b6;
    border: none;
    color: white;
    padding: 8px 14px;
    border-radius: 6px;
    cursor: pointer;
}

/* ===== POPUP ===== */
.popup {
    position: fixed;
    top: 20px;
    right: 20px;
    background: white;
    padding: 15px 20px;
    border-left: 5px solid #ff3b30;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    width: 260px;
}

.popup strong {
    display: block;
}

.popup a {
    color: #0077b6;
    cursor: pointer;
    font-size: 14px;
}
</style>
</head>

<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <h2>CATMIS</h2>
        <p>CCS Portal</p>
    </div>

    <ul>
        <li class="active"><a href="#">🏠 Admin Dashboard</a></li>
        <li><a href="tuition_assessment.html">📂 Tuition Assessment</a></li>
        <li><a href="user_management.html">👥 User Management</a></li>
        <li><a href="payment_history.html">📄 Payment History</a></li>
        <li><a href="audit_logs.html">🕒 Audit Logs</a></li>
        <li><a href="#">💾 Backup System</a></li>
    </ul>

    <div class="system-notif">
        <strong>System Notifications</strong><br>
        <span>• 2 Priority Collections</span>
    </div>

    <button class="logout-btn" onclick="logout()">Logout</button>
</div>

<!-- Main Content -->
<div class="main">
    <div class="title">Finance & Assessment Dashboard</div>

    <div class="cards">
        <div class="card">
            <h4>Total Receivables</h4>
            <?php
                $res = $conn->query("
                SELECT 
                SUM(CASE WHEN entry_type='CHARGE' THEN amount ELSE 0 END) -
                SUM(CASE WHEN entry_type='PAYMENT' THEN amount ELSE 0 END)
                AS total_receivables
                FROM student_ledgers
                ");

            $data = $res->fetch_assoc();
            ?>

<p>₱<?= number_format($data['total_receivables'],2) ?></p>
        </div>

        <div class="card">
            <h4>Active Debtors</h4>
            <p>2</p>
        </div>
    </div>
        <h3>Tuition Ledger Overview</h3>
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Search Student or Section..." onkeyup="searchTable()">
                </div>

        <div class="grade-buttons">
            <button onclick="filterGrade('all')">All</button>
            <button onclick="filterGrade('10')">Grade 10</button>
            <button onclick="filterGrade('11')">Grade 11</button>
            <button onclick="filterGrade('12')">Grade 12</button>
        </div>

<div class="section-buttons" id="sectionButtons"></div>
    <div class="table-container">

    <table id="studentTable">
        <thead>
            <tr>
                <th>Student ID</th>
                <th>Student Name</th>
                <th>Grade</th>
                <th>Section</th>
                <th>Remaining Balance</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
<tbody>
<?php

$result = $conn->query("
SELECT 
    s.student_id,
    u.full_name,
    s.grade_level,
    s.section,
    ta.account_id
FROM students s
JOIN users u ON s.user_id = u.user_id
JOIN tuition_accounts ta ON s.student_id = ta.student_id
");

while($row = $result->fetch_assoc()) {

    $balance = getBalance($conn, $row['account_id']);

    // status logic
    $status = ($balance <= 0) ? "Paid" : "Pending";

    echo "<tr>
        <td>{$row['student_id']}</td>
        <td>{$row['full_name']}</td>
        <td>{$row['grade_level']}</td>
        <td>{$row['section']}</td>
        <td>₱" . number_format($balance,2) . "</td>
        <td>$status</td>
        <td>
            " . ($status === "Pending" 
                ? "<button class='btn-payment' onclick='pay({$row['account_id']})'>Post Payment</button>" 
                : "-") . "
        </td>
    </tr>";
}
?>
</tbody>
    </table>
    </div>
</div>

<!-- Popup Notification -->
<div class="popup" id="popupBox">
    <strong>⚠ Popup Notification</strong>
    2 Overdue Accounts Detected.<br><br>
    <a onclick="dismissPopup()">Dismiss</a>
</div>

<script>

/* =========================
GLOBAL VARIABLES
========================= */

const table = document.getElementById("studentTable");
const rows = Array.from(table.querySelectorAll("tbody tr"));
let currentGrade = "all";
let currentSection = "all";


/* =========================
ALPHABETICAL SORT
========================= */

function sortAlphabetically(){

rows.sort((a,b)=>{

let nameA = a.cells[1].textContent.toLowerCase();
let nameB = b.cells[1].textContent.toLowerCase();

return nameA.localeCompare(nameB);

});

rows.forEach(row => table.querySelector("tbody").appendChild(row));

}


/* =========================
SEARCH FUNCTION
========================= */

function searchTable(){

const input = document.getElementById("searchInput").value.toLowerCase();

rows.forEach(row=>{

let name = row.cells[1].textContent.toLowerCase();
let section = row.cells[3].textContent.toLowerCase();

let match = name.includes(input) || section.includes(input);

if(match){
row.style.display="";
}else{
row.style.display="none";
}

});

}


/* =========================
GRADE FILTER
========================= */

function filterGrade(grade){

currentGrade = grade;
currentSection = "all";

let sections = new Set();

rows.forEach(row=>{

let studentGrade = row.cells[2].textContent;

if(grade==="all" || studentGrade===grade){

row.style.display="";
sections.add(row.cells[3].textContent);

}else{

row.style.display="none";

}

});

generateSectionButtons(sections);
sortAlphabetically();

}


/* =========================
SECTION BUTTON GENERATOR
========================= */

function generateSectionButtons(sections){

let container = document.getElementById("sectionButtons");

container.innerHTML="";

sections.forEach(section=>{

let btn = document.createElement("button");

btn.textContent = section;

btn.onclick = function(){
filterSection(section);
};

container.appendChild(btn);

});

}


/* =========================
SECTION FILTER
========================= */

function filterSection(section){

currentSection = section;

rows.forEach(row=>{

let studentSection = row.cells[3].textContent;

if(studentSection === section){
row.style.display="";
}else{
row.style.display="none";
}

});

sortAlphabetically();

}


/* =========================
INITIAL LOAD
========================= */

window.onload = function(){

sortAlphabetically();

};
function pay(account_id){
    window.location.href = "payment_form.php?account_id=" + account_id;
}
</script>


</body>
</html>
