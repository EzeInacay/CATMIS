<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>User Management | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
body { margin:0; font-family:Arial; background:#eef1f4; }
.sidebar { width:250px; height:100vh; position:fixed;
background:linear-gradient(to bottom,#0f2027,#203a43);
color:white; display:flex; flex-direction:column;}
.sidebar-header { padding:25px; }
.sidebar ul { list-style:none; padding:0; margin:20px 0;}
.sidebar ul li { padding:12px 25px;}
.sidebar ul li a { color:white; text-decoration:none;}
.sidebar ul li:hover { background:rgba(255,255,255,.1);}
.active { background:rgba(255,255,255,.15);}
.logout-btn { background:#ff3b30; border:none; color:white;
padding:12px; width:100%; margin-top:auto; cursor:pointer;}
.main { margin-left:250px; padding:40px;}

table { width:100%; border-collapse:collapse; background:white;
border-radius:12px; overflow:hidden;
box-shadow:0 4px 10px rgba(0,0,0,.05);}
th, td { padding:15px; text-align:left;}
th { background:#f2f4f7;}
tr:nth-child(even){ background:#fafafa;}
button.action {
background:#0077b6; color:white; border:none;
padding:6px 10px; border-radius:5px; cursor:pointer;
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
        <li><a href="tuition_assessment.html">📂 Tuition Assessment</a></li>
        <li class="active"><a href="#">👥 User Management</a></li>
        <li><a href="payment_history.html">📄 Payment History</a></li>
        <li><a href="audit_logs.html">🕒 Audit Logs</a></li>
    </ul>
    <button class="logout-btn" onclick="logout()">Logout</button>
</div>

<div class="main">
    <h2>User Management</h2>
<div style="margin-bottom:20px;">

<input type="text" id="searchInput"
placeholder="Search user..."
onkeyup="searchUsers()"
style="padding:8px;width:250px;border-radius:5px;border:1px solid #ccc;">

<button onclick="openCreateUser()" class="action">Create Account</button>

<button onclick="exportExcel()" class="action">Export Excel</button>

<button onclick="downloadTemplate()" class="action">Download Template</button>

<button onclick="triggerImport()" class="action">Import Excel</button>
<input type="file" id="importFile" accept=".csv" style="display:none;" onchange="importExcel(event)">

<input type="file" id="importFile" onchange="importExcel(event)">

</div>


<div style="margin-bottom:20px;">

<button onclick="filterRole('all')" class="action">All</button>
<button onclick="filterRole('Admin')" class="action">Admins</button>
<button onclick="filterRole('Student')" class="action">Students</button>
<button onclick="filterRole('Staff')" class="action">Staff</button>

</div>
    <table>
<thead>
<tr>
<th>ID</th>
<th>Name</th>
<th>Email</th>
<th>Role</th>
<th>Action</th>
</tr>
</thead>
        <tbody>
            <tbody id="userTable">

<tr>
<td>001</td>
<td>Admin User</td>
<td>admin@email.com</td>
<td>Admin</td>
<td>
<button class="action">Edit</button>
<button class="action" onclick="deleteRow(this)">Delete</button>
</td>
</tr>

<tr>
<td>002</td>
<td>Student User</td>
<td>user@email.com</td>
<td>Student</td>
<td>
<button class="action">Edit</button>
<button class="action" onclick="deleteRow(this)">Delete</button>
</td>
</tr>
</tbody>
    </table>
</div>
<div id="createModal"
style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;
background:rgba(0,0,0,0.4);">

<div style="background:white;padding:25px;width:300px;margin:150px auto;border-radius:10px;">

<h3>Create Account</h3>

<input id="newName" placeholder="Name" style="width:100%;margin-bottom:10px;">
<input id="newEmail" placeholder="Email" style="width:100%;margin-bottom:10px;">

<select id="newRole" style="width:100%;margin-bottom:10px;">
<option>Student</option>
<option>Admin</option>
<option>Staff</option>
</select>

<button onclick="createUser()" class="action">Create</button>
<button onclick="closeModal()" class="action">Cancel</button>

</div>
</div>


<script>
/* ======================
SEARCH USERS
====================== */

function searchUsers(){

let input = document.getElementById("searchInput").value.toLowerCase();
let rows = document.querySelectorAll("#userTable tr");

rows.forEach(row=>{

let name = row.cells[1].textContent.toLowerCase();
let email = row.cells[2].textContent.toLowerCase();
let role = row.cells[3].textContent.toLowerCase();

if(name.includes(input) || email.includes(input) || role.includes(input)){
row.style.display="";
}else{
row.style.display="none";
}

});

}


/* ======================
ROLE FILTER
====================== */

function filterRole(role){

let rows = document.querySelectorAll("#userTable tr");

rows.forEach(row=>{

let userRole = row.cells[3].textContent;

if(role==="all" || userRole===role){
row.style.display="";
}else{
row.style.display="none";
}

});

}


/* ======================
CREATE USER MODAL
====================== */

function openCreateUser(){
document.getElementById("createModal").style.display="block";
}

function closeModal(){
document.getElementById("createModal").style.display="none";
}


/* ======================
CREATE USER
====================== */

function createUser(){

let name = document.getElementById("newName").value;
let email = document.getElementById("newEmail").value;
let role = document.getElementById("newRole").value;

let table = document.getElementById("userTable");

let id = table.rows.length + 1;

let row = table.insertRow();

row.innerHTML = `
<td>${id}</td>
<td>${name}</td>
<td>${email}</td>
<td>${role}</td>
<td>
<button class="action">Edit</button>
<button class="action" onclick="deleteRow(this)">Delete</button>
</td>
`;

closeModal();

}


/* ======================
DELETE USER
====================== */

function deleteRow(btn){

let row = btn.parentNode.parentNode;
row.remove();

}


/* ======================
EXPORT TO EXCEL
====================== */

function exportExcel(){

let table = document.querySelector("table");
let html = table.outerHTML;

let url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);

let link = document.createElement("a");

link.href = url;
link.download = "CATMIS_User_Accounts.xls";

link.click();

}


/* ======================
DOWNLOAD TEMPLATE
====================== */

function downloadTemplate(){

let template = `
ID,Name,Email,Role
001,Juan Dela Cruz,juan@email.com,Student
002,Ana Santos,ana@email.com,Student
`;

let blob = new Blob([template], {type:"text/csv"});

let link = document.createElement("a");

link.href = URL.createObjectURL(blob);
link.download = "user_import_template.csv";

link.click();

}


/* ======================
IMPORT EXCEL / CSV
====================== */

function importExcel(event){

let file = event.target.files[0];

let reader = new FileReader();

reader.onload = function(e){

let lines = e.target.result.split("\n");

let table = document.getElementById("userTable");

for(let i=1;i<lines.length;i++){

let data = lines[i].split(",");

if(data.length < 4) continue;

let row = table.insertRow();

row.innerHTML = `
<td>${data[0]}</td>
<td>${data[1]}</td>
<td>${data[2]}</td>
<td>${data[3]}</td>
<td>
<button class="action">Edit</button>
<button class="action" onclick="deleteRow(this)">Delete</button>
</td>
`;

}

};

reader.readAsText(file);

}</script>

</body>
</html>
