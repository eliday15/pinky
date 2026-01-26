"""Database models and CRUD operations for ZKTeco data."""

import logging
from datetime import datetime
from typing import List, Optional

from src.database.connection import DatabaseConnection
from src.zkteco.models import AttendanceRecord, DeviceInfo, Fingerprint, User

logger = logging.getLogger(__name__)


class DatabaseModels:
    """Handles database operations for ZKTeco data."""

    def __init__(self, db: DatabaseConnection) -> None:
        """Initialize database models.

        Args:
            db: Database connection instance.
        """
        self.db = db

    def upsert_device(self, device: DeviceInfo) -> int:
        """Insert or update a device record.

        Args:
            device: Device information to upsert.

        Returns:
            Device ID.
        """
        query = """
            INSERT INTO devices (ip_address, name, serial_number, firmware_version, last_sync)
            VALUES (%s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                serial_number = VALUES(serial_number),
                firmware_version = VALUES(firmware_version),
                last_sync = VALUES(last_sync)
        """
        self.db.execute(
            query,
            (
                device.ip_address,
                device.name,
                device.serial_number,
                device.firmware_version,
                device.last_sync,
            ),
        )

        result = self.db.fetch_one(
            "SELECT id FROM devices WHERE ip_address = %s", (device.ip_address,)
        )
        return result[0] if result else 0

    def get_device_id(self, ip_address: str) -> Optional[int]:
        """Get device ID by IP address.

        Args:
            ip_address: Device IP address.

        Returns:
            Device ID or None if not found.
        """
        result = self.db.fetch_one(
            "SELECT id FROM devices WHERE ip_address = %s", (ip_address,)
        )
        return result[0] if result else None

    def upsert_users(self, device_id: int, users: List[User]) -> int:
        """Insert or update multiple user records.

        Args:
            device_id: Device ID the users belong to.
            users: List of users to upsert.

        Returns:
            Number of affected rows.
        """
        if not users:
            return 0

        query = """
            INSERT INTO users (device_id, user_id, name, privilege, password, group_id, card)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                privilege = VALUES(privilege),
                password = VALUES(password),
                group_id = VALUES(group_id),
                card = VALUES(card)
        """
        params = [
            (
                device_id,
                user.user_id,
                user.name,
                user.privilege,
                user.password,
                user.group_id,
                user.card,
            )
            for user in users
        ]

        count = self.db.execute_many(query, params)
        logger.info("Upserted %d users for device %d", len(users), device_id)
        return count

    def upsert_attendance(
        self, device_id: int, records: List[AttendanceRecord]
    ) -> int:
        """Insert attendance records, ignoring duplicates.

        Args:
            device_id: Device ID the records belong to.
            records: List of attendance records to insert.

        Returns:
            Number of inserted rows.
        """
        if not records:
            return 0

        query = """
            INSERT IGNORE INTO attendance
            (device_id, user_id, timestamp, status, punch, uid)
            VALUES (%s, %s, %s, %s, %s, %s)
        """
        params = [
            (
                device_id,
                record.user_id,
                record.timestamp,
                record.status,
                record.punch,
                record.uid,
            )
            for record in records
        ]

        count = self.db.execute_many(query, params)
        logger.info(
            "Inserted %d new attendance records for device %d",
            count,
            device_id,
        )
        return count

    def upsert_fingerprints(
        self, device_id: int, fingerprints: List[Fingerprint]
    ) -> int:
        """Insert or update fingerprint records.

        Args:
            device_id: Device ID the fingerprints belong to.
            fingerprints: List of fingerprints to upsert.

        Returns:
            Number of affected rows.
        """
        if not fingerprints:
            return 0

        query = """
            INSERT INTO fingerprints (device_id, user_id, finger_id, template)
            VALUES (%s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                template = VALUES(template)
        """
        params = [
            (device_id, fp.user_id, fp.finger_id, fp.template)
            for fp in fingerprints
        ]

        count = self.db.execute_many(query, params)
        logger.info(
            "Upserted %d fingerprints for device %d", len(fingerprints), device_id
        )
        return count

    def update_device_last_sync(self, device_id: int) -> None:
        """Update the last sync timestamp for a device.

        Args:
            device_id: Device ID to update.
        """
        self.db.execute(
            "UPDATE devices SET last_sync = %s WHERE id = %s",
            (datetime.now(), device_id),
        )

    def get_attendance_count(self, device_id: Optional[int] = None) -> int:
        """Get total attendance record count.

        Args:
            device_id: Optional device ID to filter by.

        Returns:
            Number of attendance records.
        """
        if device_id:
            result = self.db.fetch_one(
                "SELECT COUNT(*) FROM attendance WHERE device_id = %s",
                (device_id,),
            )
        else:
            result = self.db.fetch_one("SELECT COUNT(*) FROM attendance")
        return result[0] if result else 0

    def get_user_count(self, device_id: Optional[int] = None) -> int:
        """Get total user count.

        Args:
            device_id: Optional device ID to filter by.

        Returns:
            Number of users.
        """
        if device_id:
            result = self.db.fetch_one(
                "SELECT COUNT(*) FROM users WHERE device_id = %s", (device_id,)
            )
        else:
            result = self.db.fetch_one("SELECT COUNT(*) FROM users")
        return result[0] if result else 0
