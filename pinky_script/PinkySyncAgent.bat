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

REM --- First run: install dependencies ---
if not exist "venv" (
    echo  Primera ejecucion - instalando dependencias...
    echo  Esto toma ~1 minuto, solo pasa una vez.
    echo.
    python -m venv venv
    if errorlevel 1 (
        echo  ERROR: No se pudo crear el entorno virtual.
        pause
        exit /b 1
    )
    venv\Scripts\pip install --upgrade pip >nul 2>&1
    venv\Scripts\pip install pyzk mysql-connector-python python-dotenv requests
    if errorlevel 1 (
        echo  ERROR: No se pudieron instalar las dependencias.
        pause
        exit /b 1
    )
    echo.
    echo  Dependencias instaladas correctamente.
    echo.
)

REM --- Verify .env exists ---
if not exist ".env" (
    echo  ERROR: Falta el archivo .env en la carpeta.
    echo  Debe estar junto a este archivo.
    echo.
    pause
    exit /b 1
)

REM --- Run the agent ---
echo  Iniciando sincronizacion con dispositivos ZKTeco...
echo  (Deja esta ventana abierta para que siga funcionando)
echo  Para detener: cierra esta ventana o presiona Ctrl+C
echo.
echo  =============================================
echo.
venv\Scripts\python agent.py

REM --- If agent exits, pause so user sees any errors ---
echo.
echo  El agente se detuvo.
pause
