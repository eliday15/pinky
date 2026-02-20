@echo off
REM =========================================================================
REM  Pinky Sync Agent - One-time Windows installation script
REM
REM  Run as Administrator. Creates a Python venv, installs dependencies,
REM  and registers a Windows Task Scheduler task that starts the agent
REM  automatically when the PC boots.
REM =========================================================================

echo =============================================
echo  Pinky Sync Agent - Installer
echo =============================================
echo.

REM --- Check for Python ---
python --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Python is not installed or not in PATH.
    echo Please install Python 3.10+ from https://python.org
    pause
    exit /b 1
)

REM --- Get script directory ---
set SCRIPT_DIR=%~dp0
cd /d "%SCRIPT_DIR%"

REM --- Create virtual environment ---
echo Creating Python virtual environment...
python -m venv venv
if errorlevel 1 (
    echo ERROR: Failed to create virtual environment.
    pause
    exit /b 1
)

REM --- Install dependencies ---
echo Installing dependencies...
venv\Scripts\pip install --upgrade pip
venv\Scripts\pip install pyzk mysql-connector-python python-dotenv requests
if errorlevel 1 (
    echo ERROR: Failed to install dependencies.
    pause
    exit /b 1
)

REM --- Verify .env exists ---
if not exist ".env" (
    echo.
    echo WARNING: .env file not found in %SCRIPT_DIR%
    echo Please create .env with the required configuration before starting the agent.
    echo See .env.example for reference.
    echo.
)

REM --- Create Windows Task Scheduler entry ---
echo Registering agent as a scheduled task (runs at system startup)...
schtasks /create /tn "PinkySyncAgent" /tr "\"%SCRIPT_DIR%venv\Scripts\pythonw.exe\" \"%SCRIPT_DIR%agent.py\"" /sc onstart /ru SYSTEM /f
if errorlevel 1 (
    echo WARNING: Failed to create scheduled task. You may need to run this as Administrator.
    echo You can start the agent manually: venv\Scripts\python agent.py
) else (
    echo Scheduled task "PinkySyncAgent" created successfully.
)

echo.
echo =============================================
echo  Installation complete!
echo =============================================
echo.
echo The agent will start automatically when Windows boots.
echo To start it now, run: venv\Scripts\python agent.py
echo To check status:      schtasks /query /tn "PinkySyncAgent"
echo To remove:            schtasks /delete /tn "PinkySyncAgent" /f
echo.
pause
