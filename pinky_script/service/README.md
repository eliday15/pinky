# Pinky Sync Agent — Servicio de Windows

Esta carpeta convierte `PinkySyncAgent.py` en un servicio de Windows que:

- Arranca automáticamente al **prender la máquina** (no necesita que inicies sesión).
- Sobrevive a reinicios.
- **No tiene ventana ni icono** — corre en segundo plano.
- Solo un **administrador** puede detenerlo (no aparece para usuarios normales en Task Manager > Apps).
- Si el script crashea, NSSM lo **reinicia automáticamente** a los 5 segundos.

## Pre-requisitos en el servidor

1. **Python 3.11+** instalado y agregado al PATH.
2. **NSSM** (Non-Sucking Service Manager) — gratis, una sola vez:
   - Descarga: https://nssm.cc/release/nssm-2.24.zip
   - Extrae `win64\nssm.exe` y déjalo en uno de estos lugares:
     - `C:\Windows\System32\nssm.exe` (recomendado, queda global), o
     - dentro de esta misma carpeta `service\` junto a los `.bat`
3. La carpeta del proyecto copiada al servidor, por ejemplo:
   ```
   C:\pinky_script\
       PinkySyncAgent.py
       service\
           install_service.bat
           uninstall_service.bat
           README.md
   ```

## Instalar

1. **Click derecho** sobre `install_service.bat` → **Ejecutar como administrador**.
2. El script:
   - Detecta Python y NSSM automáticamente.
   - Crea el servicio `PinkySyncAgent`.
   - Lo configura como **arranque automático**.
   - Lo arranca de inmediato.
3. Verás un resumen al final con el nombre del servicio y los logs.

A partir de ese momento el agente queda corriendo, y volverá a arrancar solo en cada reinicio del servidor.

## Verificar que está corriendo

En CMD como administrador:

```cmd
nssm status PinkySyncAgent
```

Debe responder `SERVICE_RUNNING`. También puedes abrir `services.msc` (GUI de Servicios de Windows) y buscarlo en la lista.

## Ver logs

Dentro de `pinky_script\logs\`:

- `agent_YYYYMMDD.log` — log normal de la app (mismo que el modo GUI).
- `service_stdout.log` — stdout capturado por NSSM (rota a 5 MB).
- `service_stderr.log` — errores fatales / stack traces.

## Operación

```cmd
nssm restart  PinkySyncAgent
nssm stop     PinkySyncAgent
nssm start    PinkySyncAgent
nssm edit     PinkySyncAgent     :: GUI para editar la config del servicio
```

## Desinstalar

**Click derecho** sobre `uninstall_service.bat` → **Ejecutar como administrador**.

## Cómo funciona

`install_service.bat` registra esto con NSSM:

```
nssm install PinkySyncAgent <python.exe>
nssm set     PinkySyncAgent AppParameters "<...>\PinkySyncAgent.py --headless"
nssm set     PinkySyncAgent AppDirectory  <carpeta del script>
nssm set     PinkySyncAgent Start         SERVICE_AUTO_START
nssm set     PinkySyncAgent AppExit Default Restart
nssm set     PinkySyncAgent AppRestartDelay 5000
```

El flag `--headless` le dice a `PinkySyncAgent.py` que NO abra la ventana de Tk
y solo corra el loop de polling con logs a archivo. Toda la lógica de sync
(api_poll, run_device_sync, heartbeat, auto-sync diario) es exactamente la
misma que la versión GUI — solo cambia que no hay interfaz.

## Probar el modo headless sin instalar como servicio

En el servidor, desde CMD:

```cmd
cd C:\pinky_script
python PinkySyncAgent.py --headless
```

Verás los logs en stdout. `Ctrl+C` lo detiene. Si esto funciona, el servicio
también va a funcionar.
