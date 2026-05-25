@echo off
setlocal enabledelayedexpansion
title Pinky Sync Agent - Instalar servicio

REM ============================================================
REM Instala PinkySyncAgent.py como servicio de Windows usando NSSM.
REM   - Arranca al boot, sin login.
REM   - Sin ventana, sin icono, no aparece para usuarios normales.
REM   - Solo un Administrador puede detenerlo.
REM ============================================================

REM --- Requiere Administrador ---
net session >nul 2>&1
if errorlevel 1 (
    echo.
    echo  ERROR: ejecuta este archivo como Administrador.
    echo  ^(Click derecho ^> "Ejecutar como administrador"^)
    echo.
    pause
    exit /b 1
)

REM --- Localizar PinkySyncAgent.py (busca en varios lugares) ---
set PY_SCRIPT=
set SCRIPT_DIR=

REM 1. ..\PinkySyncAgent.py  (carpeta padre — layout esperado: ...\PinkySync-Agent\service\install_service.bat)
pushd "%~dp0.." >nul 2>&1
if exist "%CD%\PinkySyncAgent.py" (
    set SCRIPT_DIR=%CD%
    set PY_SCRIPT=%CD%\PinkySyncAgent.py
)
popd

REM 2. .\PinkySyncAgent.py  (mismo dir que el .bat)
if not defined PY_SCRIPT (
    if exist "%~dp0PinkySyncAgent.py" (
        set SCRIPT_DIR=%~dp0
        set PY_SCRIPT=%~dp0PinkySyncAgent.py
    )
)

REM 3. .\PinkySync-Agent\PinkySyncAgent.py  (subcarpeta vecina)
if not defined PY_SCRIPT (
    if exist "%~dp0PinkySync-Agent\PinkySyncAgent.py" (
        set SCRIPT_DIR=%~dp0PinkySync-Agent
        set PY_SCRIPT=%~dp0PinkySync-Agent\PinkySyncAgent.py
    )
)

REM 4. ..\PinkySync-Agent\PinkySyncAgent.py
if not defined PY_SCRIPT (
    pushd "%~dp0.." >nul 2>&1
    if exist "%CD%\PinkySync-Agent\PinkySyncAgent.py" (
        set SCRIPT_DIR=%CD%\PinkySync-Agent
        set PY_SCRIPT=%CD%\PinkySync-Agent\PinkySyncAgent.py
    )
    popd
)

REM 5. .\pinky_script\PinkySyncAgent.py
if not defined PY_SCRIPT (
    if exist "%~dp0pinky_script\PinkySyncAgent.py" (
        set SCRIPT_DIR=%~dp0pinky_script
        set PY_SCRIPT=%~dp0pinky_script\PinkySyncAgent.py
    )
)

if not defined PY_SCRIPT (
    echo.
    echo  ERROR: no se encontro PinkySyncAgent.py
    echo.
    echo  Busque en:
    echo    - %~dp0PinkySyncAgent.py
    echo    - %~dp0..\PinkySyncAgent.py
    echo    - %~dp0PinkySync-Agent\PinkySyncAgent.py
    echo    - %~dp0..\PinkySync-Agent\PinkySyncAgent.py
    echo    - %~dp0pinky_script\PinkySyncAgent.py
    echo.
    echo  Coloca este install_service.bat dentro de la carpeta del proyecto
    echo  o junto a la carpeta del proyecto, y vuelve a correr.
    echo.
    pause
    exit /b 1
)

REM Trim trailing backslash si lo hay
if "%SCRIPT_DIR:~-1%"=="\" set SCRIPT_DIR=%SCRIPT_DIR:~0,-1%

REM --- Localizar Python ---
set PY_EXE=
for /f "delims=" %%P in ('where python 2^>nul') do (
    if not defined PY_EXE set PY_EXE=%%P
)
if not defined PY_EXE (
    echo.
    echo  ERROR: Python no encontrado en PATH.
    echo  Instala Python 3.11+ y marca "Add Python to PATH".
    echo.
    pause
    exit /b 1
)

REM --- Localizar NSSM ---
set NSSM_EXE=
for /f "delims=" %%N in ('where nssm 2^>nul') do (
    if not defined NSSM_EXE set NSSM_EXE=%%N
)
if not defined NSSM_EXE (
    if exist "%~dp0nssm.exe" set NSSM_EXE=%~dp0nssm.exe
)
if not defined NSSM_EXE (
    echo.
    echo  ERROR: NSSM no encontrado.
    echo.
    echo  Como instalarlo:
    echo    1. Descarga https://nssm.cc/release/nssm-2.24.zip
    echo    2. Extrae nssm.exe ^(la version win64^) y copia a:
    echo         - C:\Windows\System32\nssm.exe   ^(global^), o
    echo         - %~dp0nssm.exe   ^(solo para esta carpeta^)
    echo    3. Vuelve a correr este archivo.
    echo.
    pause
    exit /b 1
)

set SVC_NAME=PinkySyncAgent
set LOG_DIR=%SCRIPT_DIR%\logs
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

REM --- Quitar instalacion previa si existe ---
"%NSSM_EXE%" status "%SVC_NAME%" >nul 2>&1
if not errorlevel 1 (
    echo.
    echo  El servicio "%SVC_NAME%" ya existe. Reinstalando...
    "%NSSM_EXE%" stop "%SVC_NAME%" >nul 2>&1
    "%NSSM_EXE%" remove "%SVC_NAME%" confirm >nul 2>&1
)

echo.
echo  Instalando servicio "%SVC_NAME%"...
echo    Python : %PY_EXE%
echo    Script : %PY_SCRIPT%
echo    Logs   : %LOG_DIR%
echo.

"%NSSM_EXE%" install "%SVC_NAME%" "%PY_EXE%"
if errorlevel 1 goto :install_failed

"%NSSM_EXE%" set "%SVC_NAME%" AppParameters "\"%PY_SCRIPT%\" --headless"
"%NSSM_EXE%" set "%SVC_NAME%" AppDirectory "%SCRIPT_DIR%"
"%NSSM_EXE%" set "%SVC_NAME%" Start SERVICE_AUTO_START
"%NSSM_EXE%" set "%SVC_NAME%" AppStdout "%LOG_DIR%\service_stdout.log"
"%NSSM_EXE%" set "%SVC_NAME%" AppStderr "%LOG_DIR%\service_stderr.log"
"%NSSM_EXE%" set "%SVC_NAME%" AppRotateFiles 1
"%NSSM_EXE%" set "%SVC_NAME%" AppRotateOnline 1
"%NSSM_EXE%" set "%SVC_NAME%" AppRotateBytes 5242880
"%NSSM_EXE%" set "%SVC_NAME%" AppExit Default Restart
"%NSSM_EXE%" set "%SVC_NAME%" AppRestartDelay 5000
"%NSSM_EXE%" set "%SVC_NAME%" AppStopMethodConsole 10000
"%NSSM_EXE%" set "%SVC_NAME%" AppStopMethodWindow 10000
"%NSSM_EXE%" set "%SVC_NAME%" AppStopMethodThreads 10000
"%NSSM_EXE%" set "%SVC_NAME%" Description "Sincroniza dispositivos ZKTeco con el servidor Pinky"

echo.
echo  Iniciando servicio...
"%NSSM_EXE%" start "%SVC_NAME%"
if errorlevel 1 goto :start_failed

echo.
echo  =============================================
echo   Servicio instalado y corriendo
echo  =============================================
echo.
echo   Nombre   : %SVC_NAME%
echo   Arranque : automatico al boot ^(no requiere login^)
echo   Logs     : %LOG_DIR%
echo.
echo   Comandos utiles ^(en CMD como admin^):
echo     nssm status   %SVC_NAME%
echo     nssm restart  %SVC_NAME%
echo     nssm stop     %SVC_NAME%
echo     nssm edit     %SVC_NAME%
echo     services.msc
echo.
pause
exit /b 0

:install_failed
echo.
echo  ERROR: NSSM install fallo.
echo.
pause
exit /b 1

:start_failed
echo.
echo  AVISO: el servicio se instalo pero no arranco.
echo  Revisa: %LOG_DIR%\service_stderr.log
echo  Y prueba: nssm start %SVC_NAME%
echo.
pause
exit /b 1
