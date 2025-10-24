@echo off
echo ===================================================
echo 🚀 Deploying project to cPanel via FTP over TLS...
echo ===================================================

set HOST=20.24.15.42
set USER=NatconDash1%%40psmeinc.org.ph
set PASS=091902Randsf2..
set REMOTE_DIR=/home/psmenow/icto.psmeinc.org.ph/
set LOCAL_DIR=C:\xampp\htdocs\NatconDash1\

REM 👉 Full path to WinSCP.com (edit if yours is in Program Files (x86))
"C:\Program Files\WinSCP\WinSCP.com" /command ^
    "open ftpes://%USER%:%PASS%@%HOST%/" ^
    "cd %REMOTE_DIR%" ^
    "lcd %LOCAL_DIR%" ^
    "put -delete *" ^
    "exit"

echo ===================================================
echo ✅ Deployment complete!
echo ===================================================
pause
