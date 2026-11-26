<?php
if(session_status() === PHP_SESSION_NONE){
    session_start();
}
Require_once __DIR__ . '/../classes/LeaveBalance.php';
Require_once __DIR__ . '/../classes/Employee.php';



// $connect = $pdo -> prepare("SELECT * FROM leave_balance WHERE employee_id = :employee_id");




// class test{


// public function testing(): array{



    
// }


// }



?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>

<style>
    .leave_balance_form {
        margin-top: 20px;
    }
    .but{
        margin-top: 20px;
        text-align: center;
    }
</style>
<body>
    

<h1>This is for Leave Balance</h1>
<form action="" method="post">
    <label for="employee_id">Employee ID:</label>
    <input type="text" name="employee_id" id="employee_id" required>
    <button type="submit">Get Leave Balance</button>
</form>

<form class = "leave_balance_form" action="" method="post">
    <label for="employee_id">Employee ID:</label>
    <input type="text" name="employee_id" id="employee_id" required>
    <button type="submit">Get Leave Balance</button>
</form>

<input type="text"  name="employee_id" id="employee_id" required>
<button class="but" type="submit">Get Leave Balance</button>


<<nav class="px-4 space-y-1">
                <a href="dashboard.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    <span class="font-medium text-sm">Dashboard</span>
                </a>
                <a href="profile.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <span class="font-medium text-sm">My Profile</span>
                </a>
                <a href="attendance.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span class="font-medium text-sm">Attendance</span>
                </a>
                <a href="file_leave.php" class="flex items-center px-4 py-2.5 bg-[#1f1d2b] text-white rounded-lg transition-colors border-l-2 border-accent">
                    <svg class="w-5 h-5 mr-3 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span class="font-medium text-sm text-accent">Leave Management</span>
                </a>
                <a href="payslips.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                    <span class="font-medium text-sm">Payslips</span>
                </a>
                <a href="settings.php" class="flex items-center px-4 py-2.5 text-textmuted hover:text-white hover:bg-[#15131e] rounded-lg transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span class="font-medium text-sm">Settings</span>
                </a>

                <div class="pt-4 mt-4 border-t border-cardborder">
                    <a href="../authentication/logout.php" class="flex items-center px-4 py-2.5 text-red-400 hover:text-red-300 hover:bg-[#15131e] rounded-lg transition-colors">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                        <span class="font-medium text-sm">Log Out</span>
                    </a>
                </div>
            </nav>


        <heaer class="flex items-center justify-between px-6 py-4 bg-[#1f1d2b] border-b border-cardborder">
            <div class="flex items-center space-x-4">
                <button id="sidebar-toggle" class="text-textmuted focus:outline-none lg:hidden">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <h1 class="text-lg font-semibold text-white">Leave Management</h1>
            </div>

            <div class="flex items-center space-x-4">
                <div class="relative">
                    <input type="text" placeholder="Search..." class="px-4 py-2 text-sm text-white bg-[#1f1d2b] border border-cardborder rounded-lg focus:outline-none focus:ring focus:border-accent">
                    <button class="absolute right-0 top-0 mt-2 mr-2 text-textmuted hover:text-white focus:outline-none">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </button>
                </div>

            

</body>
</html>


