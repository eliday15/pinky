@echo off
setlocal
title Pinky Sync Agent - Desinstalar servicio

REM --- Requiere Administrador ---
net session >nul 2>&1
if errorlevel 1 (
    echo.
    echo  ERROR: ejecuta este archivo como Administrador.
    echo.
    pause
    exit /b 1
)

set SVC_NAME=PinkySyncAgent

REM --- Localizar NSSM ---
set NSSM_EXE=
for /f "delims=" %%N in ('where nssm 2^>nul') do (
    if not defined NSSM_EXE set NSSM_EXE=%%N
)
if not defined NSSM_EXE (
    if exist "%~dp0nssm.exe" set NSSM_EXE=%~dp0nssm.exe
)
if not defined NSSM_EXE (
    echo  ERROR: NSSM no encontrado en PATH ni en esta carpeta.
    pause
    exit /b 1
)

echo.
echo  Deteniendo y removiendo servicio "%SVC_NAME%"...
"%NSSM_EXE%" stop "%SVC_NAME%" >nul 2>&1
"%NSSM_EXE%" remove "%SVC_NAME%" confirm

echo.
echo  Servicio "%SVC_NAME%" desinstalado.
echo.
pause
