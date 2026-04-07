@echo off
title Pinky Sync Agent
color 0A

echo.
echo  =============================================
echo   Pinky Sync Agent
echo  =============================================
echo.

REM --- Get script directory ---
set SCRIPT_DIR=%~dp0
cd /d "%SCRIPT_DIR%"

REM --- Check for Python ---
python --version >nul 2>&1
if errorlevel 1 (
    echo  Python no esta instalado.
    echo  Descargando Python...
    echo.
    start https://www.python.org/ftp/python/3.11.9/python-3.11.9-amd64.exe
    echo  IMPORTANTE: Marca la casilla "Add Python to PATH" al instalar.
    echo  Despues de instalar Python, ejecuta este archivo de nuevo.
    echo.
    pause
    exit /b 1
)

REM --- Launch GUI (PinkySyncAgent.py auto-installs its own dependencies on first run) ---
echo  Abriendo interfaz...
echo  (No cierres esta ventana negra mientras uses el programa)
echo.
python PinkySyncAgent.py

REM --- If GUI exits/crashes, show error and wait ---
echo.
echo  =============================================
if errorlevel 1 (
    echo  La interfaz se cerro con un error.
) else (
    echo  Programa cerrado correctamente.
)
echo  =============================================
echo.
pause
