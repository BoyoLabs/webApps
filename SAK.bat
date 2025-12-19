@echo off
setlocal enabledelayedexpansion
title Windows Troubleshooting and Diagnostics Tool

:: --- ADMIN CHECK ---
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo(
    echo ##########################################################
    echo ERROR: YOU MUST RUN THIS SCRIPT AS AN ADMINISTRATOR.
    echo Please right-click the file and select 'Run as Administrator'.
    echo ##########################################################
    echo(
    set /p "ready=Press Enter to exit..."
    exit /b
)

:MAIN_MENU
echo(
echo ============================================================
echo            WINDOWS TROUBLESHOOTING TOOL (V1.1)
echo ============================================================
echo(
echo  1. NETWORK REPAIR      (Internet, DNS, IP Issues)
echo  2. SYSTEM INTEGRITY    (SFC, DISM, File Scans)
echo  3. DISK MAINTENANCE    (CheckDisk, Temp Cleanup)
echo  4. WINDOWS UPDATE FIX  (Reset Update Services)
echo  5. SYSTEM INFORMATION  (Quick Specs Recap)
echo  6. EXIT
echo(
echo ============================================================
echo(
set /p choice="Select a category (1-6): "

if "%choice%"=="1" goto NETWORK_MENU
if "%choice%"=="2" goto SYSTEM_MENU
if "%choice%"=="3" goto DISK_MENU
if "%choice%"=="4" goto UPDATE_CONFIRM
if "%choice%"=="5" goto SYS_INFO
if "%choice%"=="6" exit
goto MAIN_MENU

:: --- CATEGORY 1: NETWORK ---
:NETWORK_MENU
echo(
echo [ NETWORK REPAIR ]
echo 1. Flush DNS Cache
echo 2. Reset TCP/IP Stack (Internet Reset)
echo 3. Release/Renew IP Address
echo 4. Reset IP/DNS to Automatic (DHCP Automator)
echo 5. Back to Main Menu
echo(
set /p netchoice="Action: "
if "%netchoice%"=="1" (ipconfig /flushdns & set /p "temp=Press Enter to continue..." & goto NETWORK_MENU)
if "%netchoice%"=="2" (netsh int ip reset & netsh winsock reset & echo Please restart after script finishes. & set /p "temp=Press Enter to continue..." & goto NETWORK_MENU)
if "%netchoice%"=="3" (ipconfig /release & ipconfig /renew & set /p "temp=Press Enter to continue..." & goto NETWORK_MENU)
if "%netchoice%"=="4" goto DHCP_AUTOMATOR
if "%netchoice%"=="5" goto MAIN_MENU
goto NETWORK_MENU

:DHCP_AUTOMATOR
echo(
echo Resetting all connected adapters to DHCP...
echo ----------------------------------------------
for /f "tokens=3*" %%a in ('netsh interface show interface ^| findstr "Connected"') do (
    echo Processing: %%b
    netsh interface ip set address name="%%b" source=dhcp
    netsh interface ip set dns name="%%b" source=dhcp
)
ipconfig /flushdns
echo ----------------------------------------------
echo Task Complete.
set /p "temp=Press Enter to return to menu..."
goto NETWORK_MENU

:: --- CATEGORY 2: SYSTEM INTEGRITY ---
:SYSTEM_MENU
echo(
echo [ SYSTEM INTEGRITY ]
echo 1. Scan System Files (SFC /Scannow)
echo 2. Repair Windows Image (DISM /RestoreHealth)
echo 3. Back to Main Menu
echo(
set /p syschoice="Action: "
if "%syschoice%"=="1" (sfc /scannow & set /p "temp=Press Enter to continue..." & goto SYSTEM_MENU)
if "%syschoice%"=="2" (dism /online /cleanup-image /restorehealth & set /p "temp=Press Enter to continue..." & goto SYSTEM_MENU)
if "%syschoice%"=="3" goto MAIN_MENU
goto SYSTEM_MENU

:: --- CATEGORY 3: DISK MAINTENANCE ---
:DISK_MENU
echo(
echo [ DISK MAINTENANCE ]
echo 1. Run CheckDisk (Scans for errors on next reboot)
echo 2. Clean Temporary Files (User and System)
echo 3. Back to Main Menu
echo(
set /p diskchoice="Action: "
if "%diskchoice%"=="1" (chkdsk C: /f & echo Disk will be checked on next reboot. & set /p "temp=Press Enter to continue..." & goto DISK_MENU)
if "%diskchoice%"=="2" (
    del /q /s /f %temp%\*
    del /q /s /f C:\Windows\Temp\*
    echo Temp files cleared.
    set /p "temp=Press Enter to continue..."
    goto DISK_MENU
)
if "%diskchoice%"=="3" goto MAIN_MENU
goto DISK_MENU

:: --- CATEGORY 4: WINDOWS UPDATE (WITH BACK-OUT OPTION) ---
:UPDATE_CONFIRM
echo(
echo [ WINDOWS UPDATE RESET ]
echo(
echo WARNING: This will stop critical update services, clear the 
echo system update cache, and restart the services.
echo This is used to fix stuck updates.
echo(
echo 1. Proceed with Repair
echo 2. Cancel and go back to Main Menu
echo(
set /p updchoice="Action: "

if "%updchoice%"=="2" goto MAIN_MENU
if "%updchoice%"=="1" goto UPDATE_FIX
goto UPDATE_CONFIRM

:UPDATE_FIX
echo(
echo Stopping services...
net stop wuauserv
net stop cryptSvc
net stop bits
net stop msiserver
echo Renaming cache folders...
ren C:\Windows\SoftwareDistribution SoftwareDistribution.old
ren C:\Windows\System32\catroot2 catroot2.old
echo Restarting services...
net start wuauserv
net start cryptSvc
net start bits
net start msiserver
echo(
echo Update services have been reset successfully.
set /p "temp=Press Enter to continue..."
goto MAIN_MENU

:: --- CATEGORY 5: SYSTEM INFO ---
:SYS_INFO
echo(
echo [ SYSTEM INFORMATION ]
echo ------------------------------------------------------------
systeminfo | findstr /B /C:"OS Name" /C:"OS Version" /C:"System Type" /C:"Total Physical Memory"
echo ------------------------------------------------------------
echo(
set /p "temp=Press Enter to return to menu..."
goto MAIN_MENU