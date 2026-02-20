#!/usr/bin/env python3
"""Pinky Sync Agent — GUI version.

Minimal tkinter interface so the user can see status at a glance.
Double-click this file (or PinkySyncAgent.bat) to launch.
"""

import logging
import os
import sys
import threading
import time
import tkinter as tk
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, Optional

import requests
from dotenv import load_dotenv

from src.config import get_config
from src.sync import Synchronizer

load_dotenv()

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
API_URL: str = os.getenv("PINKY_API_URL", "http://localhost").rstrip("/")
AGENT_KEY: str = os.getenv("PINKY_AGENT_KEY", "")
POLL_INTERVAL: int = int(os.getenv("PINKY_POLL_INTERVAL", "30"))
HEARTBEAT_INTERVAL: int = int(os.getenv("PINKY_HEARTBEAT_INTERVAL", "300"))
AUTO_SYNC_HOUR: int = int(os.getenv("PINKY_AUTO_SYNC_HOUR", "6"))

logger = logging.getLogger("pinky_agent")


# ---------------------------------------------------------------------------
# API helpers (same as agent.py)
# ---------------------------------------------------------------------------
def _headers() -> Dict[str, str]:
    """Build HTTP headers with Bearer token."""
    return {
        "Authorization": f"Bearer {AGENT_KEY}",
        "Accept": "application/json",
        "Content-Type": "application/json",
    }


def api_poll() -> Optional[Dict[str, Any]]:
    """Poll the Laravel API for a pending sync request."""
    try:
        resp = requests.get(
            f"{API_URL}/api/sync-agent/poll",
            headers=_headers(),
            timeout=15,
        )
        resp.raise_for_status()
        data = resp.json().get("data")
        return data if data else None
    except requests.RequestException as e:
        logger.error("Poll failed: %s", e)
        return None


def api_start(sync_log_id: int) -> bool:
    """Notify the API that we are starting a sync."""
    try:
        resp = requests.post(
            f"{API_URL}/api/sync-agent/{sync_log_id}/start",
            headers=_headers(),
            timeout=15,
        )
        if resp.status_code == 409:
            return False
        resp.raise_for_status()
        return True
    except requests.RequestException:
        return False


def api_done(sync_log_id: int, payload: Dict[str, Any]) -> bool:
    """Notify the API that device fetch is complete."""
    try:
        resp = requests.post(
            f"{API_URL}/api/sync-agent/{sync_log_id}/done",
            headers=_headers(),
            json=payload,
            timeout=30,
        )
        resp.raise_for_status()
        return True
    except requests.RequestException:
        return False


def api_heartbeat() -> None:
    """Send a heartbeat to the API."""
    try:
        requests.post(
            f"{API_URL}/api/sync-agent/heartbeat",
            headers=_headers(),
            timeout=10,
        )
    except requests.RequestException:
        pass


def run_device_sync() -> Dict[str, Any]:
    """Run the ZKTeco device sync."""
    config = get_config()
    synchronizer = Synchronizer(config)
    try:
        summary = synchronizer.sync_all()
        return {
            "success": summary.failed_devices < summary.total_devices,
            "devices_synced": summary.successful_devices,
            "devices_failed": summary.failed_devices,
            "total_users": summary.total_users,
            "total_attendance": summary.total_attendance,
            "error": None,
        }
    except Exception as e:
        return {
            "success": False,
            "devices_synced": 0,
            "devices_failed": 0,
            "total_users": 0,
            "total_attendance": 0,
            "error": str(e),
        }
    finally:
        synchronizer.close()


# ---------------------------------------------------------------------------
# GUI Application
# ---------------------------------------------------------------------------
class SyncAgentApp:
    """Minimal GUI for the Pinky Sync Agent."""

    COLOR_BG = "#1e1e2e"
    COLOR_CARD = "#2a2a3e"
    COLOR_TEXT = "#ffffff"
    COLOR_DIM = "#888899"
    COLOR_GREEN = "#22c55e"
    COLOR_RED = "#ef4444"
    COLOR_YELLOW = "#eab308"
    COLOR_BLUE = "#3b82f6"

    def __init__(self) -> None:
        """Initialize the GUI."""
        self.root = tk.Tk()
        self.root.title("Pinky Sync Agent")
        self.root.configure(bg=self.COLOR_BG)
        self.root.resizable(False, False)

        # Center window
        w, h = 520, 480
        x = (self.root.winfo_screenwidth() // 2) - (w // 2)
        y = (self.root.winfo_screenheight() // 2) - (h // 2)
        self.root.geometry(f"{w}x{h}+{x}+{y}")

        self._running = False
        self._stop_event = threading.Event()
        self._sync_count = 0
        self._last_sync_time: Optional[str] = None
        self._last_sync_ok: Optional[bool] = None

        self._build_ui()
        self._setup_logging()

    def _build_ui(self) -> None:
        """Build the user interface."""
        pad = {"padx": 16, "pady": 4}

        # --- Title ---
        tk.Label(
            self.root,
            text="Pinky Sync Agent",
            font=("Segoe UI", 18, "bold"),
            bg=self.COLOR_BG,
            fg=self.COLOR_TEXT,
        ).pack(pady=(16, 4))

        tk.Label(
            self.root,
            text="Sincronizacion de dispositivos ZKTeco",
            font=("Segoe UI", 10),
            bg=self.COLOR_BG,
            fg=self.COLOR_DIM,
        ).pack(pady=(0, 12))

        # --- Status card ---
        status_frame = tk.Frame(self.root, bg=self.COLOR_CARD, highlightthickness=0)
        status_frame.pack(fill="x", **pad)

        inner = tk.Frame(status_frame, bg=self.COLOR_CARD)
        inner.pack(fill="x", padx=16, pady=12)

        # Status indicator
        status_row = tk.Frame(inner, bg=self.COLOR_CARD)
        status_row.pack(fill="x")

        self._status_dot = tk.Label(
            status_row, text="\u25cf", font=("Segoe UI", 16),
            bg=self.COLOR_CARD, fg=self.COLOR_RED,
        )
        self._status_dot.pack(side="left")

        self._status_label = tk.Label(
            status_row, text="  Detenido", font=("Segoe UI", 13, "bold"),
            bg=self.COLOR_CARD, fg=self.COLOR_TEXT,
        )
        self._status_label.pack(side="left")

        # Server
        self._server_label = tk.Label(
            inner, text=f"Servidor: {API_URL}", font=("Segoe UI", 9),
            bg=self.COLOR_CARD, fg=self.COLOR_DIM, anchor="w",
        )
        self._server_label.pack(fill="x", pady=(6, 0))

        # --- Stats card ---
        stats_frame = tk.Frame(self.root, bg=self.COLOR_CARD)
        stats_frame.pack(fill="x", **pad, pady=(8, 4))

        stats_inner = tk.Frame(stats_frame, bg=self.COLOR_CARD)
        stats_inner.pack(fill="x", padx=16, pady=12)

        tk.Label(
            stats_inner, text="Ultima sincronizacion", font=("Segoe UI", 10, "bold"),
            bg=self.COLOR_CARD, fg=self.COLOR_DIM, anchor="w",
        ).pack(fill="x")

        self._last_sync_label = tk.Label(
            stats_inner, text="Ninguna aun", font=("Segoe UI", 11),
            bg=self.COLOR_CARD, fg=self.COLOR_TEXT, anchor="w",
        )
        self._last_sync_label.pack(fill="x", pady=(4, 0))

        self._last_result_label = tk.Label(
            stats_inner, text="", font=("Segoe UI", 10),
            bg=self.COLOR_CARD, fg=self.COLOR_DIM, anchor="w",
        )
        self._last_result_label.pack(fill="x")

        self._sync_count_label = tk.Label(
            stats_inner, text="Sincronizaciones hoy: 0", font=("Segoe UI", 9),
            bg=self.COLOR_CARD, fg=self.COLOR_DIM, anchor="w",
        )
        self._sync_count_label.pack(fill="x", pady=(6, 0))

        # --- Log area ---
        log_frame = tk.Frame(self.root, bg=self.COLOR_CARD)
        log_frame.pack(fill="both", expand=True, **pad, pady=(8, 4))

        tk.Label(
            log_frame, text=" Registro de actividad", font=("Segoe UI", 9, "bold"),
            bg=self.COLOR_CARD, fg=self.COLOR_DIM, anchor="w",
        ).pack(fill="x", padx=12, pady=(8, 0))

        self._log_text = tk.Text(
            log_frame, height=6, font=("Consolas", 9),
            bg="#1a1a2a", fg="#ccccdd", insertbackground="#ccccdd",
            relief="flat", wrap="word", state="disabled",
            highlightthickness=0, borderwidth=0,
        )
        self._log_text.pack(fill="both", expand=True, padx=12, pady=(4, 12))

        # --- Buttons ---
        btn_frame = tk.Frame(self.root, bg=self.COLOR_BG)
        btn_frame.pack(fill="x", padx=16, pady=(4, 16))

        self._start_btn = tk.Button(
            btn_frame, text="  Iniciar Agente  ", font=("Segoe UI", 11, "bold"),
            bg=self.COLOR_GREEN, fg="white", activebackground="#16a34a",
            relief="flat", cursor="hand2", command=self._toggle_agent,
        )
        self._start_btn.pack(side="left", ipady=4)

        self._sync_btn = tk.Button(
            btn_frame, text="  Sync Ahora  ", font=("Segoe UI", 11),
            bg=self.COLOR_BLUE, fg="white", activebackground="#2563eb",
            relief="flat", cursor="hand2", command=self._manual_sync,
            state="disabled",
        )
        self._sync_btn.pack(side="left", padx=(8, 0), ipady=4)

    def _setup_logging(self) -> None:
        """Configure logging to write to the GUI log area and file."""
        Path("logs").mkdir(exist_ok=True)

        gui_handler = _GUILogHandler(self)
        gui_handler.setLevel(logging.INFO)
        gui_handler.setFormatter(logging.Formatter("%(asctime)s  %(message)s", datefmt="%H:%M:%S"))

        file_handler = logging.FileHandler(
            f"logs/agent_{datetime.now().strftime('%Y%m%d')}.log", encoding="utf-8",
        )
        file_handler.setLevel(logging.DEBUG)
        file_handler.setFormatter(logging.Formatter("%(asctime)s - %(levelname)s - %(message)s"))

        root_logger = logging.getLogger("pinky_agent")
        root_logger.setLevel(logging.DEBUG)
        root_logger.addHandler(gui_handler)
        root_logger.addHandler(file_handler)

    def log(self, message: str) -> None:
        """Append a message to the GUI log (thread-safe).

        Args:
            message: Text to append.
        """
        def _append() -> None:
            self._log_text.configure(state="normal")
            self._log_text.insert("end", message + "\n")
            self._log_text.see("end")
            self._log_text.configure(state="disabled")
        self.root.after(0, _append)

    # --- Agent control ---
    def _toggle_agent(self) -> None:
        """Start or stop the agent loop."""
        if self._running:
            self._stop_agent()
        else:
            self._start_agent()

    def _start_agent(self) -> None:
        """Start the background agent loop."""
        if not AGENT_KEY:
            self.log("ERROR: PINKY_AGENT_KEY no esta configurado en .env")
            self._set_status("Error config", self.COLOR_RED)
            return

        self._running = True
        self._stop_event.clear()

        self._start_btn.configure(text="  Detener Agente  ", bg=self.COLOR_RED, activebackground="#dc2626")
        self._sync_btn.configure(state="normal")
        self._set_status("Conectado — esperando", self.COLOR_GREEN)

        logger.info("Agente iniciado")
        logger.info("Servidor: %s", API_URL)
        logger.info("Polling cada %ds", POLL_INTERVAL)

        thread = threading.Thread(target=self._agent_loop, daemon=True)
        thread.start()

    def _stop_agent(self) -> None:
        """Stop the background agent loop."""
        self._running = False
        self._stop_event.set()

        self._start_btn.configure(text="  Iniciar Agente  ", bg=self.COLOR_GREEN, activebackground="#16a34a")
        self._sync_btn.configure(state="disabled")
        self._set_status("Detenido", self.COLOR_RED)
        logger.info("Agente detenido")

    def _set_status(self, text: str, color: str) -> None:
        """Update the status indicator (thread-safe).

        Args:
            text: Status text.
            color: Dot color hex.
        """
        def _update() -> None:
            self._status_dot.configure(fg=color)
            self._status_label.configure(text=f"  {text}")
        self.root.after(0, _update)

    def _update_sync_result(self, result: Dict[str, Any]) -> None:
        """Update the stats card after a sync (thread-safe).

        Args:
            result: Sync result dict.
        """
        self._sync_count += 1
        self._last_sync_time = datetime.now().strftime("%H:%M:%S")
        self._last_sync_ok = result["success"]

        def _update() -> None:
            if result["success"]:
                self._last_sync_label.configure(
                    text=f"OK — {self._last_sync_time}",
                    fg=self.COLOR_GREEN,
                )
                self._last_result_label.configure(
                    text=f"{result['devices_synced']} dispositivos, "
                         f"{result['total_attendance']} registros",
                )
            else:
                self._last_sync_label.configure(
                    text=f"FALLO — {self._last_sync_time}",
                    fg=self.COLOR_RED,
                )
                self._last_result_label.configure(
                    text=str(result.get("error", "Error desconocido"))[:80],
                )
            self._sync_count_label.configure(
                text=f"Sincronizaciones hoy: {self._sync_count}",
            )
        self.root.after(0, _update)

    # --- Background loops ---
    def _agent_loop(self) -> None:
        """Main polling loop (runs in a background thread)."""
        last_heartbeat = datetime.min
        last_auto_sync_date: Optional[str] = None

        while not self._stop_event.is_set():
            try:
                now = datetime.now()

                # Heartbeat
                if (now - last_heartbeat).total_seconds() >= HEARTBEAT_INTERVAL:
                    api_heartbeat()
                    last_heartbeat = now

                # Poll
                self._set_status("Conectado — esperando", self.COLOR_GREEN)
                pending = api_poll()
                if pending:
                    self._handle_sync(pending)
                    continue

                # Daily auto-sync
                today_str = now.strftime("%Y-%m-%d")
                if now.hour == AUTO_SYNC_HOUR and last_auto_sync_date != today_str:
                    logger.info("Auto-sync diario iniciado")
                    last_auto_sync_date = today_str
                    self._do_sync()

                self._stop_event.wait(POLL_INTERVAL)

            except Exception as e:
                logger.error("Error: %s", e)
                self._set_status("Error — reintentando", self.COLOR_YELLOW)
                self._stop_event.wait(POLL_INTERVAL)

    def _handle_sync(self, sync_log: Dict[str, Any]) -> None:
        """Process a sync request from the API.

        Args:
            sync_log: SyncLog dict from poll.
        """
        sync_log_id = sync_log["id"]
        logger.info("Solicitud de sync #%d recibida", sync_log_id)

        if not api_start(sync_log_id):
            logger.warning("No se pudo iniciar sync #%d", sync_log_id)
            return

        self._set_status("Sincronizando...", self.COLOR_YELLOW)
        result = run_device_sync()
        api_done(sync_log_id, result)

        self._update_sync_result(result)
        if result["success"]:
            logger.info(
                "Sync #%d OK: %d dispositivos, %d registros",
                sync_log_id, result["devices_synced"], result["total_attendance"],
            )
        else:
            logger.error("Sync #%d FALLO: %s", sync_log_id, result.get("error"))

    def _do_sync(self) -> None:
        """Run a device sync without API interaction."""
        self._set_status("Sincronizando...", self.COLOR_YELLOW)
        result = run_device_sync()
        self._update_sync_result(result)
        if result["success"]:
            logger.info("Sync OK: %d dispositivos, %d registros",
                        result["devices_synced"], result["total_attendance"])
        else:
            logger.error("Sync FALLO: %s", result.get("error"))

    def _manual_sync(self) -> None:
        """Trigger a manual sync from the GUI button."""
        logger.info("Sync manual iniciado por usuario")
        thread = threading.Thread(target=self._do_sync, daemon=True)
        thread.start()

    def run(self) -> None:
        """Start the GUI main loop."""
        self.root.protocol("WM_DELETE_WINDOW", self._on_close)
        self.root.mainloop()

    def _on_close(self) -> None:
        """Handle window close."""
        self._stop_event.set()
        self._running = False
        self.root.destroy()


class _GUILogHandler(logging.Handler):
    """Logging handler that writes to the GUI log area."""

    def __init__(self, app: SyncAgentApp) -> None:
        super().__init__()
        self._app = app

    def emit(self, record: logging.LogRecord) -> None:
        """Emit a log record to the GUI.

        Args:
            record: The log record.
        """
        msg = self.format(record)
        self._app.log(msg)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------
def main() -> None:
    """Launch the GUI agent."""
    app = SyncAgentApp()
    app.run()


if __name__ == "__main__":
    main()
