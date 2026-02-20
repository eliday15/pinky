#!/usr/bin/env python3
"""ZKTeco Sync Agent — Polls Laravel API for sync requests.

Runs on a Windows PC in the office LAN. Connects to ZKTeco devices
via TCP/UDP and writes raw data to the remote MySQL database, then
notifies the Laravel API so it can process the data.

Usage:
    python agent.py              Run the agent (foreground)
    python agent.py --once       Run a single sync then exit
    python agent.py --help       Show help
"""

import argparse
import logging
import os
import sys
import time
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, Optional

import requests
from dotenv import load_dotenv

from src.config import get_config
from src.sync import Synchronizer

load_dotenv()

# ---------------------------------------------------------------------------
# Configuration from environment
# ---------------------------------------------------------------------------
API_URL: str = os.getenv("PINKY_API_URL", "http://localhost").rstrip("/")
AGENT_KEY: str = os.getenv("PINKY_AGENT_KEY", "")
POLL_INTERVAL: int = int(os.getenv("PINKY_POLL_INTERVAL", "30"))
HEARTBEAT_INTERVAL: int = int(os.getenv("PINKY_HEARTBEAT_INTERVAL", "300"))
AUTO_SYNC_HOUR: int = int(os.getenv("PINKY_AUTO_SYNC_HOUR", "6"))

logger = logging.getLogger("pinky_agent")


# ---------------------------------------------------------------------------
# Logging setup
# ---------------------------------------------------------------------------
def setup_logging(verbose: bool = False) -> None:
    """Configure logging for the agent.

    Args:
        verbose: Enable debug logging if True.
    """
    level = logging.DEBUG if verbose else logging.INFO
    fmt = "%(asctime)s - %(name)s - %(levelname)s - %(message)s"

    Path("logs").mkdir(exist_ok=True)

    logging.basicConfig(
        level=level,
        format=fmt,
        handlers=[
            logging.StreamHandler(sys.stdout),
            logging.FileHandler(
                f"logs/agent_{datetime.now().strftime('%Y%m%d')}.log",
                encoding="utf-8",
            ),
        ],
    )


# ---------------------------------------------------------------------------
# API helpers
# ---------------------------------------------------------------------------
def _headers() -> Dict[str, str]:
    """Build HTTP headers with Bearer token.

    Returns:
        Dict with Authorization and Accept headers.
    """
    return {
        "Authorization": f"Bearer {AGENT_KEY}",
        "Accept": "application/json",
        "Content-Type": "application/json",
    }


def api_poll() -> Optional[Dict[str, Any]]:
    """Poll the Laravel API for a pending sync request.

    Returns:
        SyncLog dict if a pending request exists, else None.
    """
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
    """Notify the API that we are starting a sync.

    Args:
        sync_log_id: The SyncLog ID to mark as running.

    Returns:
        True if the API accepted the start, False otherwise.
    """
    try:
        resp = requests.post(
            f"{API_URL}/api/sync-agent/{sync_log_id}/start",
            headers=_headers(),
            timeout=15,
        )
        if resp.status_code == 409:
            logger.warning("SyncLog #%d already started by another process.", sync_log_id)
            return False
        resp.raise_for_status()
        return True
    except requests.RequestException as e:
        logger.error("Failed to start SyncLog #%d: %s", sync_log_id, e)
        return False


def api_done(sync_log_id: int, payload: Dict[str, Any]) -> bool:
    """Notify the API that device fetch is complete.

    Args:
        sync_log_id: The SyncLog ID to report results for.
        payload: Dict with success, stats, and optional error.

    Returns:
        True if the API accepted the result, False otherwise.
    """
    try:
        resp = requests.post(
            f"{API_URL}/api/sync-agent/{sync_log_id}/done",
            headers=_headers(),
            json=payload,
            timeout=30,
        )
        resp.raise_for_status()
        return True
    except requests.RequestException as e:
        logger.error("Failed to report done for SyncLog #%d: %s", sync_log_id, e)
        return False


def api_heartbeat() -> None:
    """Send a heartbeat to the API."""
    try:
        requests.post(
            f"{API_URL}/api/sync-agent/heartbeat",
            headers=_headers(),
            timeout=10,
        )
    except requests.RequestException as e:
        logger.debug("Heartbeat failed: %s", e)


# ---------------------------------------------------------------------------
# Sync execution
# ---------------------------------------------------------------------------
def run_device_sync() -> Dict[str, Any]:
    """Run the ZKTeco device sync (reuses existing Synchronizer logic).

    Returns:
        Dict with success flag and device stats.
    """
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
        logger.exception("Device sync failed")
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


def handle_sync_request(sync_log: Dict[str, Any]) -> None:
    """Process a single sync request from the API.

    Args:
        sync_log: SyncLog dict from the poll response.
    """
    sync_log_id = sync_log["id"]
    logger.info("Processing SyncLog #%d ...", sync_log_id)

    if not api_start(sync_log_id):
        return

    result = run_device_sync()
    api_done(sync_log_id, result)

    if result["success"]:
        logger.info(
            "SyncLog #%d completed: %d devices, %d attendance records.",
            sync_log_id,
            result["devices_synced"],
            result["total_attendance"],
        )
    else:
        logger.error("SyncLog #%d failed: %s", sync_log_id, result.get("error"))


# ---------------------------------------------------------------------------
# Main loop
# ---------------------------------------------------------------------------
def run_once() -> int:
    """Run a single device sync without API interaction.

    Returns:
        Exit code (0 for success, 1 for failure).
    """
    result = run_device_sync()
    if result["success"]:
        print(f"Sync OK: {result['devices_synced']} devices, {result['total_attendance']} records.")
        return 0
    print(f"Sync FAILED: {result.get('error')}")
    return 1


def run_loop() -> None:
    """Main agent loop: poll for requests, heartbeat, and auto-sync daily."""
    logger.info("Pinky Sync Agent started.")
    logger.info("API URL: %s", API_URL)
    logger.info("Poll interval: %ds, Heartbeat interval: %ds", POLL_INTERVAL, HEARTBEAT_INTERVAL)
    logger.info("Auto-sync hour: %d:00", AUTO_SYNC_HOUR)

    if not AGENT_KEY:
        logger.error("PINKY_AGENT_KEY is not set. Exiting.")
        sys.exit(1)

    last_heartbeat = datetime.min
    last_auto_sync_date: Optional[str] = None

    while True:
        try:
            now = datetime.now()

            # --- Heartbeat ---
            if (now - last_heartbeat).total_seconds() >= HEARTBEAT_INTERVAL:
                api_heartbeat()
                last_heartbeat = now

            # --- Poll for pending requests ---
            pending = api_poll()
            if pending:
                handle_sync_request(pending)
                continue  # Check for more immediately

            # --- Daily auto-sync ---
            today_str = now.strftime("%Y-%m-%d")
            if (
                now.hour == AUTO_SYNC_HOUR
                and last_auto_sync_date != today_str
            ):
                logger.info("Triggering daily auto-sync.")
                last_auto_sync_date = today_str
                result = run_device_sync()
                if result["success"]:
                    logger.info("Daily auto-sync completed successfully.")
                else:
                    logger.error("Daily auto-sync failed: %s", result.get("error"))

            # --- Sleep ---
            time.sleep(POLL_INTERVAL)

        except KeyboardInterrupt:
            logger.info("Agent stopped by user.")
            break
        except Exception:
            logger.exception("Unexpected error in agent loop. Retrying in %ds...", POLL_INTERVAL)
            time.sleep(POLL_INTERVAL)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------
def main() -> int:
    """Main entry point for the agent.

    Returns:
        Exit code.
    """
    parser = argparse.ArgumentParser(
        description="Pinky Sync Agent — ZKTeco remote sync for Laravel",
    )
    parser.add_argument(
        "--once",
        action="store_true",
        help="Run a single device sync then exit (no API interaction)",
    )
    parser.add_argument(
        "-v", "--verbose",
        action="store_true",
        help="Enable debug logging",
    )

    args = parser.parse_args()
    setup_logging(args.verbose)

    if args.once:
        return run_once()

    run_loop()
    return 0


if __name__ == "__main__":
    sys.exit(main())
