#!/usr/bin/env python3
"""Import attendance records from a ZKTeco Excel export into the database.

Used to backfill data that was lost due to device memory purges
or sync gaps. Reads the Excel file exported from ZKTeco software
and inserts records into the raw `attendance` table.

Usage:
    python import_excel.py /path/to/excel_file.xlsx
    python import_excel.py /path/to/excel_file.xlsx --dry-run
"""

import argparse
import logging
import sys
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple

import openpyxl

from src.config import get_config
from src.database.connection import DatabaseConnection

logger = logging.getLogger(__name__)

# Mapping of Excel terminal names to database device IDs.
# Derived from cross-referencing employee punch data between
# the Excel exports and existing database records.
TERMINAL_TO_DEVICE_ID: Dict[str, int] = {
    "AlmacenPT": 7,    # 192.168.1.14 (E9)
    "Oficinas": 1,      # 192.168.1.11 (K40)
    "Produccion": 4,    # 192.168.1.12 (K40)
    "Diseno": 6,        # 192.168.1.13 (E9)
}

# Device IP addresses for UID generation
DEVICE_IPS: Dict[int, str] = {
    1: "192.168.1.11",
    4: "192.168.1.12",
    6: "192.168.1.13",
    7: "192.168.1.14",
}


def parse_excel(file_path: str) -> List[dict]:
    """Parse attendance records from a ZKTeco Excel export.

    Args:
        file_path: Path to the Excel file.

    Returns:
        List of dicts with keys: user_id, timestamp, terminal, status, punch.

    Raises:
        FileNotFoundError: If the file does not exist.
        ValueError: If the file format is unexpected.
    """
    path = Path(file_path)
    if not path.exists():
        raise FileNotFoundError(f"Excel file not found: {file_path}")

    wb = openpyxl.load_workbook(file_path, read_only=True)
    ws = wb.active

    records = []
    header_row = True

    for row in ws.iter_rows(values_only=True):
        if header_row:
            header_row = False
            # Validate expected columns
            if row[0] and str(row[0]).strip() == "ID de Usuario":
                continue
            # If first row is data (no header), process it
            header_row = False

        user_id = row[0]
        timestamp = row[2]  # "Tiempo" column
        terminal = str(row[5]).strip() if row[5] else ""  # "Nombre de la Terminal"

        if user_id is None or timestamp is None:
            continue

        try:
            user_id = int(user_id)
        except (ValueError, TypeError):
            logger.warning("Skipping row with invalid user_id: %s", user_id)
            continue

        # Handle timestamp - could be datetime object or string
        if isinstance(timestamp, datetime):
            ts = timestamp
        elif isinstance(timestamp, str):
            # Try common formats
            for fmt in ["%d/%m/%Y %H:%M:%S", "%Y-%m-%d %H:%M:%S", "%m/%d/%Y %H:%M:%S"]:
                try:
                    ts = datetime.strptime(timestamp.strip(), fmt)
                    break
                except ValueError:
                    continue
            else:
                logger.warning("Skipping row with unparseable timestamp: %s", timestamp)
                continue
        else:
            logger.warning("Skipping row with unexpected timestamp type: %s (%s)", timestamp, type(timestamp))
            continue

        records.append({
            "user_id": user_id,
            "timestamp": ts,
            "terminal": terminal,
            "status": 0,  # fingerprint
            "punch": 0,
        })

    wb.close()
    logger.info("Parsed %d records from Excel file", len(records))
    return records


def insert_records(db: DatabaseConnection, records: List[dict]) -> Tuple[int, int]:
    """Insert attendance records into the database.

    Uses INSERT IGNORE to skip duplicates (same device_id, user_id, timestamp).

    Args:
        db: Database connection.
        records: List of parsed attendance records.

    Returns:
        Tuple of (total_attempted, new_inserted).
    """
    query = """
        INSERT IGNORE INTO attendance
        (device_id, user_id, timestamp, status, punch, uid)
        VALUES (%s, %s, %s, %s, %s, %s)
    """

    params = []
    skipped = 0

    for record in records:
        terminal = record["terminal"]
        device_id = TERMINAL_TO_DEVICE_ID.get(terminal)

        if device_id is None:
            logger.warning("Unknown terminal '%s' for user %d, skipping", terminal, record["user_id"])
            skipped += 1
            continue

        ip_address = DEVICE_IPS[device_id]
        ts = record["timestamp"]
        uid = f"{ip_address}_{record['user_id']}_{ts.isoformat()}"

        params.append((
            device_id,
            record["user_id"],
            ts,
            record["status"],
            record["punch"],
            uid,
        ))

    if not params:
        logger.warning("No valid records to insert")
        return 0, 0

    # Insert in batches of 500
    batch_size = 500
    total_inserted = 0

    conn = db.connect()
    cursor = conn.cursor()

    try:
        for i in range(0, len(params), batch_size):
            batch = params[i:i + batch_size]
            cursor.executemany(query, batch)
            total_inserted += cursor.rowcount
            logger.info(
                "Batch %d: %d/%d records inserted",
                (i // batch_size) + 1,
                cursor.rowcount,
                len(batch),
            )

        conn.commit()
    except Exception as e:
        conn.rollback()
        logger.error("Failed to insert records: %s", e)
        raise
    finally:
        cursor.close()

    if skipped > 0:
        logger.warning("Skipped %d records with unknown terminals", skipped)

    return len(params), total_inserted


def main() -> int:
    """Main entry point.

    Returns:
        Exit code (0 for success, 1 for failure).
    """
    parser = argparse.ArgumentParser(
        description="Import ZKTeco Excel attendance export into database",
    )
    parser.add_argument("file", help="Path to the Excel file (.xlsx)")
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Parse and validate without inserting into database",
    )
    parser.add_argument(
        "-v", "--verbose",
        action="store_true",
        help="Enable verbose logging",
    )

    args = parser.parse_args()

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    )

    print("=" * 60)
    print("ZKTeco Excel Import Tool")
    print("=" * 60)
    print(f"File: {args.file}")
    print(f"Mode: {'DRY RUN' if args.dry_run else 'LIVE INSERT'}")
    print("=" * 60)

    try:
        records = parse_excel(args.file)
    except (FileNotFoundError, ValueError) as e:
        print(f"Error: {e}")
        return 1

    if not records:
        print("No records found in Excel file")
        return 1

    # Show summary
    dates = sorted(set(r["timestamp"].strftime("%Y-%m-%d") for r in records))
    users = len(set(r["user_id"] for r in records))
    terminals = sorted(set(r["terminal"] for r in records))

    print(f"\nRecords: {len(records)}")
    print(f"Users: {users}")
    print(f"Dates: {dates[0]} to {dates[-1]} ({len(dates)} days)")
    print(f"Terminals: {', '.join(terminals)}")

    # Check terminal mapping
    unknown = [t for t in terminals if t not in TERMINAL_TO_DEVICE_ID]
    if unknown:
        print(f"\nWARNING: Unknown terminals: {unknown}")
        print("These records will be skipped")

    if args.dry_run:
        print("\n[DRY RUN] No data was inserted.")
        return 0

    config = get_config()
    db = DatabaseConnection(config.mysql)

    try:
        total, inserted = insert_records(db, records)
        print(f"\nResults:")
        print(f"  Total records attempted: {total}")
        print(f"  New records inserted: {inserted}")
        print(f"  Duplicates skipped: {total - inserted}")
        return 0
    except Exception as e:
        print(f"Error inserting records: {e}")
        return 1
    finally:
        db.disconnect()


if __name__ == "__main__":
    sys.exit(main())
