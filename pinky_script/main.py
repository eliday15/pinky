#!/usr/bin/env python3
"""Main entry point for Pinky Clock - ZKTeco to MySQL synchronization tool.

Usage:
    python main.py --init-db    Initialize database schema
    python main.py --sync       Synchronize data from all devices
    python main.py --help       Show help message
"""

import argparse
import logging
import sys
from datetime import datetime

from src.config import get_config
from src.database.connection import DatabaseConnection
from src.sync import Synchronizer


def setup_logging(verbose: bool = False) -> None:
    """Configure logging for the application.

    Args:
        verbose: Enable debug logging if True.
    """
    level = logging.DEBUG if verbose else logging.INFO
    format_str = "%(asctime)s - %(name)s - %(levelname)s - %(message)s"

    # Ensure logs directory exists
    from pathlib import Path
    Path("logs").mkdir(exist_ok=True)

    logging.basicConfig(
        level=level,
        format=format_str,
        handlers=[
            logging.StreamHandler(sys.stdout),
            logging.FileHandler(
                f"logs/sync_{datetime.now().strftime('%Y%m%d')}.log",
                encoding="utf-8",
            ),
        ],
    )


def init_database() -> int:
    """Initialize the database schema.

    Returns:
        Exit code (0 for success, 1 for failure).
    """
    config = get_config()
    db = DatabaseConnection(config.mysql)

    try:
        print(f"Connecting to MySQL at {config.mysql.host}:{config.mysql.port}...")
        db.connect()
        print("Initializing database schema...")
        db.init_schema()
        print("Database schema initialized successfully!")
        return 0
    except Exception as e:
        print(f"Error initializing database: {e}")
        return 1
    finally:
        db.disconnect()


def run_sync() -> int:
    """Run synchronization from all configured devices.

    Returns:
        Exit code (0 for success, 1 for failure).
    """
    config = get_config()

    print("=" * 60)
    print("Pinky Clock - ZKTeco Synchronization")
    print("=" * 60)
    print(f"Devices: {', '.join(config.zkteco.devices)}")
    print(f"Database: {config.mysql.database}@{config.mysql.host}")
    print("=" * 60)
    print()

    synchronizer = Synchronizer(config)

    try:
        summary = synchronizer.sync_all()

        print()
        print("=" * 60)
        print("SYNCHRONIZATION SUMMARY")
        print("=" * 60)
        print(f"Duration: {(summary.end_time - summary.start_time).total_seconds():.2f} seconds")
        print(f"Devices: {summary.successful_devices}/{summary.total_devices} successful")
        print(f"Users synced: {summary.total_users}")
        print(f"Attendance records: {summary.total_attendance}")
        print(f"Fingerprints: {summary.total_fingerprints}")
        print()

        print("DEVICE DETAILS:")
        print("-" * 60)

        for result in summary.results:
            status = "OK" if result.success else "FAILED"
            print(f"  {result.ip_address}: {status}")

            if result.success:
                print(f"    - Users: {result.users_synced}")
                print(f"    - Attendance: {result.attendance_synced}")
                print(f"    - Fingerprints: {result.fingerprints_synced}")
            else:
                print(f"    - Error: {result.error}")

        print("=" * 60)

        return 0 if summary.failed_devices == 0 else 1

    except Exception as e:
        print(f"Error during synchronization: {e}")
        logging.exception("Synchronization failed")
        return 1
    finally:
        synchronizer.close()


def main() -> int:
    """Main entry point.

    Returns:
        Exit code.
    """
    parser = argparse.ArgumentParser(
        description="Pinky Clock - ZKTeco to MySQL Synchronization Tool",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python main.py --init-db     Create database tables
  python main.py --sync        Sync data from all devices
  python main.py --sync -v     Sync with verbose logging
        """,
    )

    parser.add_argument(
        "--init-db",
        action="store_true",
        help="Initialize database schema (create tables)",
    )
    parser.add_argument(
        "--sync",
        action="store_true",
        help="Synchronize data from all configured ZKTeco devices",
    )
    parser.add_argument(
        "-v",
        "--verbose",
        action="store_true",
        help="Enable verbose (debug) logging",
    )

    args = parser.parse_args()

    if not args.init_db and not args.sync:
        parser.print_help()
        return 0

    setup_logging(args.verbose)

    if args.init_db:
        return init_database()

    if args.sync:
        return run_sync()

    return 0


if __name__ == "__main__":
    sys.exit(main())
